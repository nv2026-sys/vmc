<?php

namespace App\Services;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobCallLog;
use App\Models\JobExternalReference;
use App\Models\JobStatusHistory;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use App\Notifications\DispatchAssigned;
use App\Notifications\DriverRemarkReplyAlert;
use App\Notifications\JobAssignmentAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentWorkflow
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function visibleJobs(User $actor): Builder
    {
        return $this->scopeJobsToActor(EmergencyJob::query(), $actor);
    }

    public function visibleArchivedJobs(User $actor): Builder
    {
        return $this->scopeJobsToActor(EmergencyJob::onlyTrashed(), $actor);
    }

    public function archive(User $actor, EmergencyJob $job): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);

            if (! $this->isArchivable($job) || $job->assignments()->whereNull('released_at')->exists()) {
                throw ValidationException::withMessages([
                    'job' => 'Only closed incidents with no active unit assignments can be archived.',
                ]);
            }

            $job->delete();
            $this->auditLogger->record($actor, 'jobs.archived', $job, ['job_id' => $job->job_id]);

            return $job;
        });
    }

    public function restoreArchived(User $actor, int $jobId): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $jobId): EmergencyJob {
            $job = $this->visibleArchivedJobs($actor)->lockForUpdate()->findOrFail($jobId);
            $job->restore();
            $this->auditLogger->record($actor, 'jobs.restored', $job, ['job_id' => $job->job_id]);

            return $job;
        });
    }

    public function isArchivable(EmergencyJob $job): bool
    {
        return in_array($job->status, [
            EmergencyJob::STATUS_COMPLETED,
            EmergencyJob::STATUS_DUPLICATE,
            EmergencyJob::STATUS_TRANSFERRED_TO_112,
        ], true);
    }

    private function scopeJobsToActor(Builder $jobs, User $actor): Builder
    {
        if ($actor->hasRole('VEHICLE_DRIVER')) {
            return $jobs->whereHas('assignments', function (Builder $assignments) use ($actor): void {
                $assignments->whereNull('released_at')
                    ->where('assigned_driver_user_id', $actor->id);
            });
        }

        if (! $this->canViewAllStations($actor)) {
            $stationIds = $this->stationIds($actor);
            $jobs->where(function (Builder $query) use ($stationIds): void {
                $query->whereHas(
                    'ward',
                    fn (Builder $wards) => $wards->withTrashed()->whereHas(
                        'fireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    ),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
                );
            });
        }

        return $jobs;
    }

    public function callers(User $actor, string $search): Collection
    {
        $query = $this->visibleJobs($actor)
            ->with('ward:id,name,code')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->where('status', '!=', EmergencyJob::STATUS_DUPLICATE);

        $digits = preg_replace('/\D+/', '', $search) ?? '';
        if (strlen($digits) >= 3) {
            $query->whereRaw($this->normalizedPhoneSql().' LIKE ?', ['%'.$digits.'%']);
        } else {
            $query->where(function (Builder $matches) use ($search): void {
                $matches->whereLike('caller_name', '%'.$search.'%')
                    ->orWhereLike('society_name', '%'.$search.'%');
            });
        }

        return $query->orderByDesc('occurred_at')->limit(10)->get();
    }

    public function possibleDuplicates(User $actor, string $phone, ?int $exceptId = null): Collection
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 7) {
            return new Collection;
        }

        return $this->visibleJobs($actor)
            ->with('ward:id,name,code')
            ->where('occurred_at', '>=', now()->subHours(48))
            ->where('status', '!=', EmergencyJob::STATUS_DUPLICATE)
            ->whereRaw($this->normalizedPhoneSql().' = ?', [$digits])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();
    }

    public function findVisibleJobOrFail(User $actor, int $id): EmergencyJob
    {
        return $this->visibleJobs($actor)->findOrFail($id);
    }

    public function assignableStations(User $actor, EmergencyJob $job): Collection
    {
        $stations = FireStation::query()
            ->where('is_active', true)
            ->when(
                ! $this->canViewAllStations($actor),
                fn (Builder $query) => $query->whereKey($this->stationIds($actor)),
            )
            ->when(
                $job->ward_id !== null,
                fn (Builder $query) => $query->whereHas(
                    'wards',
                    fn (Builder $wards) => $wards->where('wards.id', $job->ward_id),
                ),
            )
            ->with([
                'vehicles' => fn (Builder|Relation $vehicles) => $vehicles
                    ->whereIn('status', [Vehicle::STATUS_AVAILABLE, Vehicle::STATUS_DEPLOYED])
                    ->where('operational_status', Vehicle::OPERATIONAL_STATUS_ON_ROAD)
                    ->orderBy('vehicle_number'),
                'teams' => fn (Builder|Relation $teams) => $teams
                    ->whereIn('status', [Team::STATUS_AVAILABLE, Team::STATUS_DEPLOYED])
                    ->orderBy('name'),
                'users' => fn (Builder|Relation $users) => $users
                    ->where('users.is_active', true)
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.code', 'VEHICLE_DRIVER'))
                    ->orderBy('users.name')
                    ->select('users.id', 'users.name'),
            ])
            ->orderBy('name')
            ->get();

        return $stations->filter(fn (FireStation $station): bool => $station->vehicles->isNotEmpty()
            && $station->users->isNotEmpty());
    }

    public function canUpdateAssignment(User $actor, JobAssignment $assignment): bool
    {
        if ($assignment->status === JobAssignment::STATUS_QUEUED) {
            return false;
        }

        if ($this->canViewAllStations($actor)) {
            return true;
        }

        if ($actor->hasRole('VEHICLE_DRIVER')) {
            return (int) $assignment->assigned_driver_user_id === (int) $actor->getKey();
        }

        if ($this->stationIds($actor)->contains($assignment->fire_station_id)) {
            return true;
        }

        $team = $assignment->team;

        return $team !== null && (
            (int) $team->leader_user_id === (int) $actor->getKey()
            || $team->members()->where('users.id', $actor->id)->exists()
        );
    }

    public function register(User $actor, array $data): EmergencyJob
    {
        $ward = Ward::query()->whereKey($data['ward_id'])->where('is_active', true)->first();
        if (! $ward) {
            throw ValidationException::withMessages(['ward_id' => 'Select an active ward.']);
        }

        if (! $this->canViewAllStations($actor)) {
            $stationIds = $this->stationIds($actor);
            if (! $ward->fireStations()->whereIn('fire_stations.id', $stationIds)->exists()) {
                throw ValidationException::withMessages(['ward_id' => 'The selected ward is outside your station scope.']);
            }
        }

        $job = DB::transaction(function () use ($actor, $data, $ward): EmergencyJob {
            $job = EmergencyJob::query()->create([
                ...$data,
                'job_id' => $this->newJobId($data['society_name'], $ward->code),
                'occurred_at' => $data['occurred_at'],
                'status' => EmergencyJob::STATUS_RECEIVED,
                'created_by' => $actor->id,
            ]);

            JobCallLog::query()->create([
                'emergency_job_id' => $job->id,
                'caller_name' => $job->caller_name,
                'caller_phone' => $job->caller_phone,
                'call_source' => $job->call_source,
                'received_at' => now(),
                'notes' => $job->remarks,
                'created_by' => $actor->id,
            ]);

            $this->recordStatus($job, null, EmergencyJob::STATUS_RECEIVED, $actor, 'Incident registered.');
            $this->auditLogger->record($actor, 'jobs.created', $job, [
                'job_id' => $job->job_id,
                'ward_id' => $job->ward_id,
                'priority' => $job->priority,
            ]);

            return $job;
        });

        return $job->load('ward');
    }

    public function closeAsDuplicate(User $actor, EmergencyJob $job, int $originalId, ?string $remarks): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job, $originalId, $remarks): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);
            $original = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($originalId);

            if ($job->is($original)) {
                throw ValidationException::withMessages(['duplicate_of' => 'An incident cannot be a duplicate of itself.']);
            }

            if ($original->status === EmergencyJob::STATUS_DUPLICATE) {
                throw ValidationException::withMessages(['duplicate_of' => 'Choose the original incident, not another duplicate.']);
            }

            if ($job->status !== EmergencyJob::STATUS_RECEIVED || $job->assignments()->exists()) {
                throw ValidationException::withMessages(['job' => 'Only unassigned, newly received incidents can be closed as duplicates.']);
            }

            if ($this->normalizedPhone($job->caller_phone) !== $this->normalizedPhone($original->caller_phone)) {
                throw ValidationException::withMessages(['duplicate_of' => 'The original incident must have the same caller phone number.']);
            }

            $job->update(['status' => EmergencyJob::STATUS_DUPLICATE]);
            JobExternalReference::query()->create([
                'emergency_job_id' => $job->id,
                'reference_type' => 'DUPLICATE_OF',
                'reference_number' => $original->job_id,
                'details' => $remarks,
                'confirmed_at' => now(),
                'created_by' => $actor->id,
            ]);
            $this->recordStatus($job, EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_DUPLICATE, $actor, $remarks);
            $this->auditLogger->record($actor, 'jobs.duplicate_closed', $job, [
                'job_id' => $job->job_id,
                'duplicate_of' => $original->job_id,
            ]);

            return $job->load('ward', 'externalReferences');
        });
    }

    public function transferTo112(User $actor, EmergencyJob $job, string $referenceNumber, ?string $details): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job, $referenceNumber, $details): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);

            if ($job->status !== EmergencyJob::STATUS_RECEIVED || $job->assignments()->exists()) {
                throw ValidationException::withMessages(['job' => 'Only unassigned, newly received incidents can be transferred to 112.']);
            }

            $job->update(['status' => EmergencyJob::STATUS_TRANSFERRED_TO_112]);
            JobExternalReference::query()->create([
                'emergency_job_id' => $job->id,
                'reference_type' => '112',
                'reference_number' => $referenceNumber,
                'details' => $details,
                'transferred_at' => now(),
                'created_by' => $actor->id,
            ]);
            $this->recordStatus(
                $job,
                EmergencyJob::STATUS_RECEIVED,
                EmergencyJob::STATUS_TRANSFERRED_TO_112,
                $actor,
                $details,
            );
            $this->auditLogger->record($actor, 'jobs.transferred_to_112', $job, [
                'job_id' => $job->job_id,
                'reference_number' => $referenceNumber,
            ]);

            return $job->load('ward', 'externalReferences');
        });
    }

    public function assignResources(User $actor, EmergencyJob $job, array $data): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job, $data): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);

            if (! in_array($job->status, [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED], true)) {
                throw ValidationException::withMessages([
                    'assignments' => 'Resources can only be assigned to received or assigned incidents.',
                ]);
            }

            $vehicleIds = array_column($data['assignments'], 'vehicle_id');
            if (count($vehicleIds) !== count(array_unique($vehicleIds))) {
                throw ValidationException::withMessages(['assignments' => 'A vehicle can only be assigned once to an incident.']);
            }

            $teamIds = array_filter(array_column($data['assignments'], 'team_id'));
            if (count($teamIds) !== count(array_unique($teamIds))) {
                throw ValidationException::withMessages(['assignments' => 'A team can only be assigned once to an incident.']);
            }

            $initialStatus = $job->status;

            foreach ($data['assignments'] as $resource) {
                $station = FireStation::query()
                    ->whereKey($resource['fire_station_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $station || (! $this->canViewAllStations($actor) && ! $this->stationIds($actor)->contains($station->id))) {
                    throw ValidationException::withMessages([
                        'assignments' => 'The selected station is outside your access scope or inactive.',
                    ]);
                }

                if ($job->ward_id && ! $station->wards()->where('wards.id', $job->ward_id)->exists()) {
                    throw ValidationException::withMessages([
                        'assignments' => 'The selected station does not cover this incident ward.',
                    ]);
                }

                $vehicle = Vehicle::query()
                    ->whereKey($resource['vehicle_id'])
                    ->where('fire_station_id', $station->id)
                    ->lockForUpdate()
                    ->first();

                if (! $vehicle
                    || ! in_array($vehicle->status, [Vehicle::STATUS_AVAILABLE, Vehicle::STATUS_DEPLOYED], true)
                    || $vehicle->operational_status !== Vehicle::OPERATIONAL_STATUS_ON_ROAD
                    || $job->assignments()->where('vehicle_id', $vehicle->id)->whereNull('released_at')->exists()) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Each selected vehicle must be On Road and cannot already be assigned to this incident.',
                    ]);
                }

                $driver = User::query()
                    ->whereKey($resource['driver_user_id'])
                    ->where('is_active', true)
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.code', 'VEHICLE_DRIVER'))
                    ->whereHas('fireStations', fn (Builder $stations) => $stations->whereKey($station->id))
                    ->lockForUpdate()
                    ->first();

                if (! $driver) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Select an active vehicle driver assigned to the selected station.',
                    ]);
                }

                $activeVehicleConflict = JobAssignment::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->whereNull('released_at')
                    ->where('status', '!=', JobAssignment::STATUS_QUEUED)
                    ->where(function (Builder $pair) use ($driver): void {
                        $pair->whereNull('assigned_driver_user_id')
                            ->orWhere('assigned_driver_user_id', '!=', $driver->id);
                    })
                    ->exists();
                $activeDriverConflict = JobAssignment::query()
                    ->where('assigned_driver_user_id', $driver->id)
                    ->whereNull('released_at')
                    ->where('status', '!=', JobAssignment::STATUS_QUEUED)
                    ->where('vehicle_id', '!=', $vehicle->id)
                    ->exists();
                $queuedVehicleConflict = JobAssignment::query()
                    ->where('vehicle_id', $vehicle->id)
                    ->whereNull('released_at')
                    ->where('status', JobAssignment::STATUS_QUEUED)
                    ->where(function (Builder $pair) use ($driver): void {
                        $pair->whereNull('assigned_driver_user_id')
                            ->orWhere('assigned_driver_user_id', '!=', $driver->id);
                    })
                    ->exists();
                $queuedDriverConflict = JobAssignment::query()
                    ->where('assigned_driver_user_id', $driver->id)
                    ->whereNull('released_at')
                    ->where('status', JobAssignment::STATUS_QUEUED)
                    ->where('vehicle_id', '!=', $vehicle->id)
                    ->exists();

                if ($activeVehicleConflict || $activeDriverConflict || $queuedVehicleConflict || $queuedDriverConflict) {
                    throw ValidationException::withMessages([
                        'assignments' => 'A busy vehicle and driver can only be queued together as the same pair.',
                    ]);
                }

                $team = null;
                if (! empty($resource['team_id'])) {
                    $team = Team::query()
                        ->whereKey($resource['team_id'])
                        ->where('fire_station_id', $station->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $team || ! in_array($team->status, [Team::STATUS_AVAILABLE, Team::STATUS_DEPLOYED], true)) {
                        throw ValidationException::withMessages([
                            'assignments' => 'Each selected team must be active at its selected station.',
                        ]);
                    }
                }

                $isQueued = JobAssignment::query()
                    ->whereNull('released_at')
                    ->where(function (Builder $assignments) use ($vehicle, $driver, $team): void {
                        $assignments->where('vehicle_id', $vehicle->id)
                            ->orWhere('assigned_driver_user_id', $driver->id);
                        if ($team) {
                            $assignments->orWhere('team_id', $team->id);
                        }
                    })
                    ->exists();

                $assignment = JobAssignment::query()->create([
                    'emergency_job_id' => $job->id,
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'team_id' => $team?->id,
                    'team_leader_user_id' => $team?->leader_user_id,
                    'assigned_driver_user_id' => $driver->id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                    'status' => $isQueued
                        ? JobAssignment::STATUS_QUEUED
                        : JobAssignment::STATUS_ASSIGNED,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);
                $team?->update(['status' => Team::STATUS_DEPLOYED]);
                $this->recordStatus(
                    $job,
                    null,
                    $assignment->status,
                    $actor,
                    $data['remarks'] ?? (
                        $isQueued
                            ? 'Response resource queued behind an active assignment.'
                            : 'Response resource assigned.'
                    ),
                    $assignment,
                );
                $this->auditLogger->record($actor, $isQueued ? 'jobs.resource_queued' : 'jobs.resource_assigned', $assignment, [
                    'job_id' => $job->job_id,
                    'vehicle_id' => $vehicle->id,
                    'team_id' => $team?->id,
                    'driver_user_id' => $driver->id,
                    'fire_station_id' => $station->id,
                ]);

                $recipientIds = collect([$driver->id]);
                if ($team) {
                    $recipientIds = $recipientIds
                        ->merge([$assignment->team_leader_user_id])
                        ->merge($team->members()->whereNull('team_members.left_at')->pluck('users.id'));
                }
                $dispatch = [
                    'assignment_id' => $assignment->id,
                    'incident_id' => $job->id,
                    'job_id' => $job->job_id,
                    'incident_type' => $job->incident_type,
                    'priority' => $job->priority,
                    'caller_name' => $job->caller_name,
                    'caller_phone' => $job->caller_phone,
                    'society_name' => $job->society_name,
                    'incident_address' => $job->incident_address,
                    'fire_station' => $station->name,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'driver_user_id' => $driver->id,
                    'message' => $isQueued
                        ? 'A response job is queued and will be activated when the current assignment finishes.'
                        : 'A new emergency response job has been assigned to your vehicle.',
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'status' => $assignment->status,
                ];

                User::query()->whereKey($recipientIds->filter()->unique())->get()
                    ->each(fn (User $recipient) => $recipient->notify(
                        $isQueued
                            ? new JobAssignmentAlert([
                                'event' => 'assignment.queued',
                                ...$dispatch,
                            ])
                            : new DispatchAssigned($dispatch),
                    ));

                $this->notifyAssignmentUsers(
                    $assignment,
                    $actor,
                    [
                        'event' => $isQueued ? 'assignment.queued' : 'assignment.created',
                        'assignment_id' => (int) $assignment->id,
                        'incident_id' => (int) $job->id,
                        'job_id' => $job->job_id,
                        'incident_type' => $job->incident_type,
                        'vehicle_number' => $vehicle->vehicle_number,
                        'team_name' => $team?->name,
                        'station_name' => $station->name,
                        'driver_name' => $driver->name,
                        'status' => $assignment->status,
                        'message' => $isQueued
                            ? 'A response unit was queued behind an active assignment.'
                            : 'A response unit was assigned to this incident.',
                        'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    ],
                    $recipientIds->filter()->unique()->all(),
                );
            }

            $nextJobStatus = $this->aggregateJobStatus($job);
            if ($nextJobStatus !== $initialStatus) {
                $job->update(['status' => $nextJobStatus]);
                $this->recordStatus(
                    $job,
                    $initialStatus,
                    $nextJobStatus,
                    $actor,
                    $data['remarks'] ?? 'Response resources assigned or queued.',
                );
            }

            return $job->load([
                'ward',
                'assignments.fireStation',
                'assignments.vehicle',
                'assignments.team',
                'assignments.teamLeader',
                'assignments.assignedDriver',
                'statusHistory.actor',
                'statusHistory.assignment.vehicle',
            ]);
        });
    }

    public function updateAssignmentStatus(
        User $actor,
        EmergencyJob $job,
        int $assignmentId,
        array $data,
    ): EmergencyJob {
        return DB::transaction(function () use ($actor, $job, $assignmentId, $data): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);
            $assignment = $job->assignments()->lockForUpdate()->findOrFail($assignmentId);

            $team = $assignment->team;
            if (! $this->canUpdateAssignment($actor, $assignment)) {
                throw ValidationException::withMessages(['assignment' => 'This assignment is outside your access scope.']);
            }

            $previousAssignmentStatus = $assignment->status;
            $nextStatus = $data['status'];
            if ($assignment->released_at !== null && ! in_array($nextStatus, [
                JobAssignment::STATUS_COMPLETED,
                JobAssignment::STATUS_WORK_DONE,
            ], true) && JobAssignment::query()
                ->whereNull('released_at')
                ->whereKeyNot($assignment->id)
                ->where(function (Builder $assignments) use ($assignment): void {
                    $assignments->where('vehicle_id', $assignment->vehicle_id)
                        ->orWhere('assigned_driver_user_id', $assignment->assigned_driver_user_id);
                    if ($assignment->team_id !== null) {
                        $assignments->orWhere('team_id', $assignment->team_id);
                    }
                })
                ->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'This assignment cannot be reopened while its vehicle, driver, or team has another active or queued assignment.',
                ]);
            }
            $previousJobStatus = $job->status;
            $wasUnreleased = $assignment->released_at === null;
            $assignment->update([
                'status' => $nextStatus,
                'released_at' => in_array($nextStatus, [
                    JobAssignment::STATUS_COMPLETED,
                    JobAssignment::STATUS_WORK_DONE,
                ], true)
                    ? (isset($data['changed_at']) ? Carbon::parse($data['changed_at']) : now())
                    : null,
                'remarks' => $data['remarks'] ?? $assignment->remarks,
                'cancellation_reason' => null,
            ]);
            $changedAt = isset($data['changed_at']) ? Carbon::parse($data['changed_at']) : now();
            $this->recordStatus(
                $job,
                $previousAssignmentStatus,
                $nextStatus,
                $actor,
                $data['remarks'] ?? null,
                $assignment,
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
                $changedAt,
            );

            $vehicle = $assignment->vehicle()->lockForUpdate()->firstOrFail();
            $vehicle->update([
                'status' => $vehicle->assignments()->whereNull('released_at')->exists()
                    ? Vehicle::STATUS_DEPLOYED
                    : Vehicle::STATUS_AVAILABLE,
            ]);
            if ($team) {
                $team = $team->newQuery()->lockForUpdate()->findOrFail($team->id);
                $team->update([
                    'status' => $team->assignments()->whereNull('released_at')->exists()
                        ? Team::STATUS_DEPLOYED
                        : Team::STATUS_AVAILABLE,
                ]);
            }

            $nextJobStatus = $this->aggregateJobStatus($job);
            if ($nextJobStatus !== $previousJobStatus) {
                $job->update(['status' => $nextJobStatus]);
                $this->recordStatus(
                    $job,
                    $previousJobStatus,
                    $nextJobStatus,
                    $actor,
                    $data['remarks'] ?? null,
                    changedAt: $changedAt,
                );
            }

            $this->auditLogger->record($actor, 'jobs.assignment_status_updated', $assignment, [
                'job_id' => $job->job_id,
                'previous_status' => $previousAssignmentStatus,
                'new_status' => $nextStatus,
            ]);
            $remark = trim((string) ($data['remarks'] ?? ''));
            if ($remark !== '' && $assignment->assignedDriver && $assignment->assigned_driver_user_id !== $actor->id) {
                $assignment->assignedDriver->notify(new DriverRemarkReplyAlert([
                    'assignment_id' => (int) $assignment->id,
                    'incident_id' => (int) $job->id,
                    'job_id' => $job->job_id,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'status' => $nextStatus,
                    'from_name' => $actor->name,
                    'reply' => $remark,
                    'message' => 'New response on incident '.$job->job_id.': '.$remark,
                ]));
            }
            if ($previousAssignmentStatus !== $nextStatus) {
                $this->notifyAssignmentUsers($assignment, $actor, [
                    'event' => 'assignment.status_changed',
                    'assignment_id' => (int) $assignment->id,
                    'incident_id' => (int) $job->id,
                    'job_id' => $job->job_id,
                    'incident_type' => $job->incident_type,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'team_name' => $team?->name,
                    'station_name' => $assignment->fireStation?->name,
                    'driver_name' => $assignment->assignedDriver?->name,
                    'previous_status' => $previousAssignmentStatus,
                    'new_status' => $nextStatus,
                    'changed_by' => $actor->name,
                    'message' => sprintf(
                        '%s changed the assignment status from %s to %s.',
                        $actor->name,
                        str_replace('_', ' ', strtolower($previousAssignmentStatus)),
                        str_replace('_', ' ', strtolower($nextStatus)),
                    ),
                    'changed_at' => $changedAt->toIso8601String(),
                ]);
            }

            if ($wasUnreleased && $assignment->released_at !== null) {
                $this->promoteQueuedAssignments($actor, $assignment);
            }

            return $job->load([
                'ward',
                'assignments.fireStation',
                'assignments.vehicle',
                'assignments.team',
                'assignments.teamLeader',
                'assignments.assignedDriver',
                'statusHistory.actor',
                'statusHistory.assignment.vehicle',
            ]);
        });
    }

    public function declineAssignment(User $actor, JobAssignment $requestedAssignment, string $reason): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $requestedAssignment, $reason): EmergencyJob {
            $job = EmergencyJob::query()->lockForUpdate()->findOrFail($requestedAssignment->emergency_job_id);
            $assignment = $job->assignments()->lockForUpdate()->findOrFail($requestedAssignment->id);

            if (! $this->canUpdateAssignment($actor, $assignment)) {
                throw ValidationException::withMessages([
                    'assignment' => 'This assignment is outside your access scope.',
                ]);
            }

            if ($assignment->released_at !== null || $assignment->status !== JobAssignment::STATUS_ASSIGNED) {
                throw ValidationException::withMessages([
                    'status' => 'Only a pending assignment can be declined.',
                ]);
            }

            $previousJobStatus = $job->status;
            $assignment->update([
                'status' => JobAssignment::STATUS_DECLINED,
                'released_at' => now(),
                'cancellation_reason' => $reason,
            ]);
            $this->recordStatus(
                $job,
                JobAssignment::STATUS_ASSIGNED,
                JobAssignment::STATUS_DECLINED,
                $actor,
                $reason,
                $assignment,
            );

            $vehicle = $assignment->vehicle()->lockForUpdate()->firstOrFail();
            $vehicle->update([
                'status' => $vehicle->assignments()->whereNull('released_at')->exists()
                    ? Vehicle::STATUS_DEPLOYED
                    : Vehicle::STATUS_AVAILABLE,
            ]);

            $team = $assignment->team;
            if ($team && ! $team->assignments()->whereNull('released_at')->exists()) {
                $team->update(['status' => Team::STATUS_AVAILABLE]);
            }

            $nextJobStatus = $this->aggregateJobStatus($job);
            if ($nextJobStatus !== $previousJobStatus) {
                $job->update(['status' => $nextJobStatus]);
                $this->recordStatus($job, $previousJobStatus, $nextJobStatus, $actor, $reason);
            }

            $this->auditLogger->record($actor, 'jobs.assignment_declined', $assignment, [
                'job_id' => $job->job_id,
                'reason' => $reason,
            ]);
            $this->notifyAssignmentUsers($assignment, $actor, [
                'event' => 'assignment.status_changed',
                'assignment_id' => (int) $assignment->id,
                'incident_id' => (int) $job->id,
                'job_id' => $job->job_id,
                'incident_type' => $job->incident_type,
                'vehicle_number' => $assignment->vehicle?->vehicle_number,
                'team_name' => $team?->name,
                'station_name' => $assignment->fireStation?->name,
                'driver_name' => $assignment->assignedDriver?->name,
                'previous_status' => JobAssignment::STATUS_ASSIGNED,
                'new_status' => JobAssignment::STATUS_DECLINED,
                'changed_by' => $actor->name,
                'message' => $actor->name.' declined the assigned response unit: '.$reason,
                'changed_at' => now()->toIso8601String(),
            ]);

            $this->promoteQueuedAssignments($actor, $assignment);

            return $job->load([
                'ward',
                'assignments.fireStation',
                'assignments.vehicle',
                'assignments.team',
                'assignments.teamLeader',
                'assignments.assignedDriver',
                'statusHistory.actor',
                'statusHistory.assignment.vehicle',
            ]);
        });
    }

    private function aggregateJobStatus(EmergencyJob $job): string
    {
        $assignments = $job->assignments()->get(['status', 'released_at']);
        $unreleasedStatuses = $assignments
            ->whereNull('released_at')
            ->pluck('status');
        $queuedAssignments = $unreleasedStatuses->filter(
            fn (string $status): bool => $status === JobAssignment::STATUS_QUEUED,
        );
        $activeStatuses = $unreleasedStatuses->reject(
            fn (string $status): bool => $status === JobAssignment::STATUS_QUEUED,
        );

        if ($activeStatuses->isEmpty()) {
            if ($queuedAssignments->isNotEmpty()) {
                return EmergencyJob::STATUS_ASSIGNED;
            }

            return $assignments->contains(
                fn (JobAssignment $assignment): bool => in_array($assignment->status, [
                    JobAssignment::STATUS_COMPLETED,
                    JobAssignment::STATUS_WORK_DONE,
                ], true),
            )
                ? EmergencyJob::STATUS_COMPLETED
                : EmergencyJob::STATUS_RECEIVED;
        }

        $statuses = $activeStatuses;
        if ($queuedAssignments->isEmpty() && $activeStatuses->every(fn (string $status): bool => in_array($status, [
            JobAssignment::STATUS_COMPLETED,
            JobAssignment::STATUS_WORK_DONE,
        ], true))) {
            return EmergencyJob::STATUS_COMPLETED;
        }

        $rank = [
            JobAssignment::STATUS_ASSIGNED => 0,
            JobAssignment::STATUS_EN_ROUTE => 1,
            JobAssignment::STATUS_ARRIVED => 2,
            JobAssignment::STATUS_STARTED => 2,
            JobAssignment::STATUS_IN_PROGRESS => 3,
            JobAssignment::STATUS_COMPLETED => 3,
            JobAssignment::STATUS_WORK_DONE => 3,
        ];
        $status = $statuses
            ->filter(fn (string $value): bool => ! in_array($value, [
                JobAssignment::STATUS_COMPLETED,
                JobAssignment::STATUS_WORK_DONE,
            ], true))
            ->sortByDesc(fn (string $value): int => $rank[$value] ?? 0)
            ->first();

        return match ($status) {
            JobAssignment::STATUS_ASSIGNED => EmergencyJob::STATUS_ASSIGNED,
            JobAssignment::STATUS_EN_ROUTE => EmergencyJob::STATUS_EN_ROUTE,
            JobAssignment::STATUS_ARRIVED, JobAssignment::STATUS_STARTED => EmergencyJob::STATUS_ARRIVED,
            JobAssignment::STATUS_IN_PROGRESS => EmergencyJob::STATUS_IN_PROGRESS,
            default => EmergencyJob::STATUS_RECEIVED,
        };
    }

    private function promoteQueuedAssignments(User $actor, JobAssignment $releasedAssignment): void
    {
        $queuedAssignments = JobAssignment::query()
            ->whereNull('released_at')
            ->where('status', JobAssignment::STATUS_QUEUED)
            ->where(function (Builder $assignments) use ($releasedAssignment): void {
                $assignments->where('vehicle_id', $releasedAssignment->vehicle_id)
                    ->orWhere('assigned_driver_user_id', $releasedAssignment->assigned_driver_user_id);
                if ($releasedAssignment->team_id !== null) {
                    $assignments->orWhere('team_id', $releasedAssignment->team_id);
                }
            })
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->activateQueuedAssignments($actor, $queuedAssignments);
    }

    public function activateQueuedAssignmentsForVehicle(User $actor, Vehicle $vehicle): void
    {
        DB::transaction(function () use ($actor, $vehicle): void {
            $queuedAssignments = JobAssignment::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereNull('released_at')
                ->where('status', JobAssignment::STATUS_QUEUED)
                ->orderBy('assigned_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->activateQueuedAssignments($actor, $queuedAssignments);
        });
    }

    /**
     * @param  Collection<int, JobAssignment>  $queuedAssignments
     */
    private function activateQueuedAssignments(User $actor, Collection $queuedAssignments): void
    {
        foreach ($queuedAssignments as $queued) {
            $hasEarlierResourceQueue = JobAssignment::query()
                ->whereNull('released_at')
                ->where('status', JobAssignment::STATUS_QUEUED)
                ->where(function (Builder $earlier) use ($queued): void {
                    $earlier->where('assigned_at', '<', $queued->assigned_at)
                        ->orWhere(function (Builder $tie) use ($queued): void {
                            $tie->where('assigned_at', $queued->assigned_at)
                                ->where('id', '<', $queued->id);
                        });
                })
                ->where(function (Builder $earlier) use ($queued): void {
                    $earlier->where('vehicle_id', $queued->vehicle_id)
                        ->orWhere('assigned_driver_user_id', $queued->assigned_driver_user_id);
                    if ($queued->team_id !== null) {
                        $earlier->orWhere('team_id', $queued->team_id);
                    }
                })
                ->exists();
            if ($hasEarlierResourceQueue) {
                continue;
            }

            $hasActiveResourceConflict = JobAssignment::query()
                ->whereNull('released_at')
                ->where('status', '!=', JobAssignment::STATUS_QUEUED)
                ->whereKeyNot($queued->id)
                ->where(function (Builder $active) use ($queued): void {
                    $active->where('vehicle_id', $queued->vehicle_id)
                        ->orWhere('assigned_driver_user_id', $queued->assigned_driver_user_id);
                    if ($queued->team_id !== null) {
                        $active->orWhere('team_id', $queued->team_id);
                    }
                })
                ->exists();
            if ($hasActiveResourceConflict) {
                continue;
            }

            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($queued->vehicle_id);
            if ($vehicle->operational_status !== Vehicle::OPERATIONAL_STATUS_ON_ROAD) {
                continue;
            }

            $previousJobStatus = EmergencyJob::query()
                ->lockForUpdate()
                ->findOrFail($queued->emergency_job_id)
                ->status;
            $changedAt = now();
            $queued->update([
                'status' => JobAssignment::STATUS_ASSIGNED,
                'remarks' => $queued->remarks ?: 'Automatically activated from the resource queue.',
            ]);
            $this->recordStatus(
                $queued->emergencyJob,
                JobAssignment::STATUS_QUEUED,
                JobAssignment::STATUS_ASSIGNED,
                $actor,
                'Queued assignment automatically activated after its resources became available.',
                $queued,
                changedAt: $changedAt,
            );

            $nextJobStatus = $this->aggregateJobStatus($queued->emergencyJob);
            if ($nextJobStatus !== $previousJobStatus) {
                $queued->emergencyJob->update(['status' => $nextJobStatus]);
                $this->recordStatus(
                    $queued->emergencyJob,
                    $previousJobStatus,
                    $nextJobStatus,
                    $actor,
                    'Queued response resources were activated.',
                    changedAt: $changedAt,
                );
            }

            $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);
            $team = $queued->team;
            if ($team) {
                $team->newQuery()->lockForUpdate()->findOrFail($team->id)
                    ->update(['status' => Team::STATUS_DEPLOYED]);
            }

            $queued->loadMissing([
                'emergencyJob',
                'fireStation',
                'vehicle',
                'team',
                'teamLeader',
                'assignedDriver',
            ]);
            $recipientIds = collect([$queued->assigned_driver_user_id]);
            if ($team) {
                $recipientIds = $recipientIds
                    ->merge([$queued->team_leader_user_id])
                    ->merge($team->members()->whereNull('team_members.left_at')->pluck('users.id'));
            }
            $dispatch = [
                'assignment_id' => $queued->id,
                'incident_id' => $queued->emergency_job_id,
                'job_id' => $queued->emergencyJob->job_id,
                'incident_type' => $queued->emergencyJob->incident_type,
                'priority' => $queued->emergencyJob->priority,
                'caller_name' => $queued->emergencyJob->caller_name,
                'caller_phone' => $queued->emergencyJob->caller_phone,
                'society_name' => $queued->emergencyJob->society_name,
                'incident_address' => $queued->emergencyJob->incident_address,
                'fire_station' => $queued->fireStation->name,
                'vehicle_number' => $vehicle->vehicle_number,
                'driver_user_id' => $queued->assigned_driver_user_id,
                'message' => 'A queued emergency response assignment is now active.',
                'assigned_at' => $changedAt->toIso8601String(),
                'status' => JobAssignment::STATUS_ASSIGNED,
            ];
            User::query()->whereKey($recipientIds->filter()->unique())->get()
                ->each(fn (User $recipient) => $recipient->notify(new DispatchAssigned($dispatch)));

            $this->notifyAssignmentUsers(
                $queued,
                $actor,
                [
                    'event' => 'assignment.status_changed',
                    'assignment_id' => (int) $queued->id,
                    'incident_id' => (int) $queued->emergency_job_id,
                    'job_id' => $queued->emergencyJob->job_id,
                    'incident_type' => $queued->emergencyJob->incident_type,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'team_name' => $team?->name,
                    'station_name' => $queued->fireStation->name,
                    'driver_name' => $queued->assignedDriver?->name,
                    'previous_status' => JobAssignment::STATUS_QUEUED,
                    'new_status' => JobAssignment::STATUS_ASSIGNED,
                    'changed_by' => $actor->name,
                    'message' => 'A queued response assignment is now active.',
                    'changed_at' => $changedAt->toIso8601String(),
                ],
                $recipientIds->filter()->unique()->all(),
            );
            $this->auditLogger->record($actor, 'jobs.resource_queue_activated', $queued, [
                'job_id' => $queued->emergencyJob->job_id,
                'vehicle_id' => $vehicle->id,
                'team_id' => $team?->id,
                'driver_user_id' => $queued->assigned_driver_user_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $excludedUserIds
     */
    private function notifyAssignmentUsers(
        JobAssignment $assignment,
        User $actor,
        array $data,
        array $excludedUserIds = [],
    ): void {
        $stationId = (int) $assignment->fire_station_id;
        $participantIds = collect([
            $assignment->assigned_driver_user_id,
            $assignment->team_leader_user_id,
            $assignment->assigned_by,
        ])->merge(
            $assignment->team?->members()
                ->whereNull('team_members.left_at')
                ->pluck('users.id') ?? collect(),
        )
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => in_array($id, $excludedUserIds, true))
            ->reject(fn (int $id): bool => $id === (int) $actor->id)
            ->values();

        User::query()
            ->where('is_active', true)
            ->whereKeyNot($actor->id)
            ->when(
                $excludedUserIds !== [],
                fn (Builder $users) => $users->whereNotIn('users.id', $excludedUserIds),
            )
            ->where(function (Builder $users) use ($stationId, $participantIds): void {
                $stationRecipients = function (Builder $scoped) use ($stationId): void {
                    $scoped->whereHas('roles', fn (Builder $roles) => $roles
                        ->whereHas('permissions', fn (Builder $permissions) => $permissions
                            ->where('permissions.code', 'dashboard.view')))
                        ->whereHas('roles', fn (Builder $roles) => $roles
                            ->whereHas('permissions', fn (Builder $permissions) => $permissions
                                ->where('permissions.code', 'jobs.view')))
                        ->where(fn (Builder $authorized) => $authorized
                            ->whereHas('roles', fn (Builder $roles) => $roles
                                ->whereIn('roles.code', ['SUPER_ADMIN', 'CONTROL_ROOM_ADMIN']))
                            ->orWhereHas('fireStations', fn (Builder $stations) => $stations
                                ->whereKey($stationId))
                            ->orWhereHas('managedFireStations', fn (Builder $stations) => $stations
                                ->whereKey($stationId)));
                };
                $users->where($stationRecipients);
                if ($participantIds->isNotEmpty()) {
                    $users->orWhereIn('users.id', $participantIds);
                }
            })
            ->get()
            ->each(fn (User $recipient) => $recipient->notify(new JobAssignmentAlert($data)));
    }

    private function recordStatus(
        EmergencyJob $job,
        ?string $previous,
        string $next,
        User $actor,
        ?string $remarks,
        ?JobAssignment $assignment = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?Carbon $changedAt = null,
    ): void {
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'job_assignment_id' => $assignment?->id,
            'previous_status' => $previous,
            'new_status' => $next,
            'changed_by' => $actor->id,
            'changed_at' => $changedAt ?? now(),
            'remarks' => $remarks,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    private function newJobId(string $societyName, string $wardCode): string
    {
        $location = Str::slug($societyName);
        $location = $location !== '' ? $location : Str::slug($wardCode);
        $location = Str::limit($location !== '' ? $location : 'area', 14, '');

        return 'VMC-'.Str::upper($location).'-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }

    private function normalizedPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        return strlen($digits) === 11 && str_starts_with($digits, '0')
            ? substr($digits, 1)
            : $digits;
    }

    private function normalizedPhoneSql(): string
    {
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(caller_phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '/', '')";

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            return "CASE WHEN LEN({$digits}) = 12 AND SUBSTRING({$digits}, 1, 2) = '91' THEN SUBSTRING({$digits}, 3, 32) WHEN LEN({$digits}) = 11 AND SUBSTRING({$digits}, 1, 1) = '0' THEN SUBSTRING({$digits}, 2, 32) ELSE {$digits} END";
        }

        return "CASE WHEN LENGTH({$digits}) = 12 AND SUBSTR({$digits}, 1, 2) = '91' THEN SUBSTR({$digits}, 3) WHEN LENGTH({$digits}) = 11 AND SUBSTR({$digits}, 1, 1) = '0' THEN SUBSTR({$digits}, 2) ELSE {$digits} END";
    }

    private function canViewAllStations(User $actor): bool
    {
        return $actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function stationIds(User $actor): \Illuminate\Support\Collection
    {
        return $actor->fireStations()->pluck('fire_stations.id')
            ->merge($actor->managedFireStations()->pluck('id'))
            ->unique()
            ->values();
    }
}
