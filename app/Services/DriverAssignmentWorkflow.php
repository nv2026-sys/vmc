<?php

namespace App\Services;

use App\Models\JobAssignment;
use App\Models\JobAssignmentAction;
use App\Models\JobRemark;
use App\Models\JobStatusHistory;
use App\Models\User;
use App\Notifications\DriverAssignmentActionAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverAssignmentWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly IncidentWorkflow $incidentWorkflow,
        private readonly VehicleTrackingWorkflow $trackingWorkflow,
    ) {}

    public function active(User $driver): ?JobAssignment
    {
        $this->ensureDriver($driver);

        return $this->assignmentsFor($driver)
            ->whereNull('released_at')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->first();
    }

    public function findActiveAssignment(User $driver, string $identifier): JobAssignment
    {
        $this->ensureDriver($driver);

        return $this->assignmentQuery($driver, $identifier)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function details(User $driver, string $identifier, bool $completed = false): JobAssignment
    {
        $this->ensureDriver($driver);

        $query = $this->assignmentQuery($driver, $identifier);
        if ($completed) {
            $query->whereNotNull('released_at');
        } else {
            $query->whereNull('released_at');
        }

        return $query->firstOrFail();
    }

    public function attend(User $driver, string $identifier, array $data): JobAssignment
    {
        return DB::transaction(function () use ($driver, $identifier, $data): JobAssignment {
            $assignment = $this->findActiveAssignment($driver, $identifier);
            $location = $this->recordGps($driver, $assignment, $data, 'driverLatitude', 'driverLongitude');
            $this->incidentWorkflow->updateAssignmentStatus($driver, $assignment->emergencyJob, $assignment->id, [
                'status' => JobAssignment::STATUS_EN_ROUTE,
                'remarks' => 'Driver accepted and started the assignment.',
                ...$location,
            ]);

            return $this->details($driver, (string) $assignment->id);
        });
    }

    public function decline(User $driver, string $identifier, string $reason): JobAssignment
    {
        return DB::transaction(function () use ($driver, $identifier, $reason): JobAssignment {
            $assignment = $this->findActiveAssignment($driver, $identifier);
            $this->incidentWorkflow->declineAssignment($driver, $assignment, $reason);
            $this->recordActionEvent($driver, $assignment, 'declined', ['reason' => $reason]);

            return $this->details($driver, (string) $assignment->id, true);
        });
    }

    /**
     * @return array{assignment: JobAssignment, notified: int}
     */
    public function escalate(User $driver, string $identifier, string $reason): array
    {
        return $this->recordAlert($driver, $identifier, 'escalated', [
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{assignment: JobAssignment, notified: int}
     */
    public function requestHelp(
        User $driver,
        string $identifier,
        string $reason,
        ?string $comments,
        ?string $helpType = null,
    ): array {
        return $this->recordAlert($driver, $identifier, 'need_help', [
            'reason' => $reason,
            'help_type' => $helpType,
            'comments' => $comments,
        ]);
    }

    public function reached(User $driver, string $identifier, array $data): JobAssignment
    {
        return DB::transaction(function () use ($driver, $identifier, $data): JobAssignment {
            $assignment = $this->findActiveAssignment($driver, $identifier);
            $changedAt = isset($data['reachedTime']) ? Carbon::parse($data['reachedTime']) : now();
            $this->ensureChronological($assignment, $changedAt);
            $location = $this->recordGps($driver, $assignment, $data, 'reachedLatitude', 'reachedLongitude');
            $this->incidentWorkflow->updateAssignmentStatus($driver, $assignment->emergencyJob, $assignment->id, [
                'status' => JobAssignment::STATUS_ARRIVED,
                'remarks' => 'Driver reached the incident location.',
                'changed_at' => $changedAt,
                ...$location,
            ]);

            return $this->details($driver, (string) $assignment->id);
        });
    }

    /**
     * @return array{assignment: JobAssignment, selected_issues: array<int, string>, issue_remarks: ?string}
     */
    public function reportIssues(User $driver, string $identifier, array $selectedIssues, ?string $issueRemarks): array
    {
        return DB::transaction(function () use (
            $driver,
            $identifier,
            $selectedIssues,
            $issueRemarks,
        ): array {
            $assignment = $this->findActiveAssignment($driver, $identifier);
            $issueText = implode('; ', $selectedIssues);
            $body = 'Driver action [reported issues] assignment #'.$assignment->id.': '.$issueText;
            if ($issueRemarks !== null && $issueRemarks !== '') {
                $body .= "\nRemarks: ".$issueRemarks;
            }

            $this->appendRemark($driver, $assignment, $body);
            $this->recordActionEvent($driver, $assignment, 'reported_issues', [
                'selected_issues' => $selectedIssues,
                'issue_remarks' => $issueRemarks,
            ]);
            $this->auditLogger->record($driver, 'driver.assignment.issues_reported', $assignment, [
                'job_id' => $assignment->emergencyJob->job_id,
                'selected_issues' => $selectedIssues,
                'issue_remarks' => $issueRemarks,
            ]);

            return [
                'assignment' => $this->details($driver, (string) $assignment->id),
                'selected_issues' => $selectedIssues,
                'issue_remarks' => $issueRemarks,
            ];
        });
    }

    public function complete(User $driver, string $identifier, array $data): JobAssignment
    {
        return DB::transaction(function () use ($driver, $identifier, $data): JobAssignment {
            $assignment = $this->findActiveAssignment($driver, $identifier);
            $location = $this->recordGps($driver, $assignment, $data, 'driverLatitude', 'driverLongitude');
            $changedAt = isset($data['completedAt']) ? Carbon::parse($data['completedAt']) : now();
            $this->ensureChronological($assignment, $changedAt);

            if ($assignment->status === JobAssignment::STATUS_ARRIVED) {
                $this->incidentWorkflow->updateAssignmentStatus(
                    $driver,
                    $assignment->emergencyJob,
                    $assignment->id,
                    [
                        'status' => JobAssignment::STATUS_IN_PROGRESS,
                        'remarks' => 'Incident response work started.',
                        'changed_at' => $changedAt,
                        ...$location,
                    ],
                );
            }

            $this->incidentWorkflow->updateAssignmentStatus(
                $driver,
                $assignment->emergencyJob,
                $assignment->id,
                [
                    'status' => JobAssignment::STATUS_COMPLETED,
                    'remarks' => $data['completionNotes'] ?? 'Driver completed the assignment.',
                    'changed_at' => $changedAt,
                    ...$location,
                ],
            );

            if (! empty($data['completionNotes'])) {
                $this->appendRemark(
                    $driver,
                    $assignment,
                    'Driver action [completed] assignment #'.$assignment->id.': '.$data['completionNotes'],
                );
            }
            $this->recordActionEvent($driver, $assignment, 'completed', [
                'completion_notes' => $data['completionNotes'] ?? null,
                'completed_at' => $changedAt->toIso8601String(),
            ]);

            return $this->details($driver, (string) $assignment->id, true);
        });
    }

    public function close(User $driver, string $identifier, string $reason): JobAssignment
    {
        return DB::transaction(function () use ($driver, $identifier, $reason): JobAssignment {
            $assignment = $this->assignmentQuery($driver, $identifier)
                ->whereNotNull('released_at')
                ->lockForUpdate()
                ->firstOrFail();
            if ($assignment->status !== JobAssignment::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'assignment' => 'Only a completed assignment can be closed.',
                ]);
            }

            $prefix = 'Driver action [closed] assignment #'.$assignment->id.':';
            $alreadyClosed = $assignment->emergencyJob
                ->remarksHistory()
                ->where('user_id', $driver->id)
                ->where('body', 'like', $prefix.'%')
                ->exists();
            if ($alreadyClosed) {
                throw ValidationException::withMessages([
                    'assignment' => 'This completed assignment has already been closed.',
                ]);
            }

            $this->appendRemark($driver, $assignment, $prefix.' '.$reason);
            $this->recordActionEvent($driver, $assignment, 'closed', ['reason' => $reason]);
            $this->auditLogger->record($driver, 'driver.assignment.closed', $assignment, [
                'job_id' => $assignment->emergencyJob->job_id,
                'reason' => $reason,
            ]);

            return $this->details($driver, (string) $assignment->id, true);
        });
    }

    /**
     * @return array{assignment: JobAssignment, notified: int}
     */
    private function recordAlert(User $driver, string $identifier, string $action, array $details): array
    {
        return DB::transaction(function () use ($driver, $identifier, $action, $details): array {
            $assignment = $this->findActiveAssignment($driver, $identifier);
            $body = 'Driver action ['.$action.'] assignment #'.$assignment->id.': '.$details['reason'];
            if (! empty($details['comments'])) {
                $body .= "\nComments: ".$details['comments'];
            }
            $this->appendRemark($driver, $assignment, $body);
            $this->recordActionEvent($driver, $assignment, $action, $details);

            $stationId = (int) $assignment->fire_station_id;
            $recipients = User::query()
                ->where('is_active', true)
                ->whereKeyNot($driver->id)
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('permissions.code', 'dashboard.view')))
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->whereHas('permissions', fn (Builder $permissions) => $permissions->where('permissions.code', 'jobs.view')))
                ->where(fn (Builder $users) => $users
                    ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('roles.code', ['SUPER_ADMIN', 'CONTROL_ROOM_ADMIN']))
                    ->orWhereHas('fireStations', fn (Builder $stations) => $stations->whereKey($stationId))
                    ->orWhereHas('managedFireStations', fn (Builder $stations) => $stations->whereKey($stationId)))
                ->get();
            $notificationData = [
                'action' => $action,
                'assignment_id' => (int) $assignment->id,
                'incident_id' => (int) $assignment->emergency_job_id,
                'job_id' => $assignment->emergencyJob->job_id,
                'vehicle_number' => $assignment->vehicle->vehicle_number,
                'team_name' => $assignment->team?->name,
                'station_name' => $assignment->fireStation?->name,
                'driver_id' => (int) $driver->id,
                'driver_name' => $driver->name,
                'incident_type' => $assignment->emergencyJob->incident_type,
                'incident_location' => $assignment->emergencyJob->incident_address,
                'requested_at' => now()->toIso8601String(),
                ...$details,
            ];
            $recipients->each(fn (User $recipient) => $recipient->notify(
                new DriverAssignmentActionAlert($notificationData),
            ));

            $this->auditLogger->record($driver, 'driver.assignment.'.$action, $assignment, [
                'job_id' => $assignment->emergencyJob->job_id,
                ...$details,
                'control_room_recipients' => $recipients->count(),
            ]);

            return [
                'assignment' => $this->details($driver, (string) $assignment->id),
                'notified' => $recipients->count(),
            ];
        });
    }

    /**
     * @return array<string, int|float>
     */
    private function recordGps(
        User $driver,
        JobAssignment $assignment,
        array $data,
        string $latitudeKey,
        string $longitudeKey,
    ): array {
        if (! isset($data[$latitudeKey], $data[$longitudeKey])) {
            return [];
        }

        $location = [
            'assignment_id' => $assignment->id,
            'latitude' => (float) $data[$latitudeKey],
            'longitude' => (float) $data[$longitudeKey],
        ];
        $speedKey = $latitudeKey === 'reachedLatitude' ? null : 'currentSpeedKmh';
        if ($speedKey !== null && isset($data[$speedKey])) {
            $location['speed_kph'] = (float) $data[$speedKey];
        }

        $this->trackingWorkflow->recordLocation($driver, $assignment->vehicle, $location);

        return [
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
        ];
    }

    private function appendRemark(User $driver, JobAssignment $assignment, string $body): JobRemark
    {
        return $assignment->emergencyJob->remarksHistory()->create([
            'user_id' => $driver->id,
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function recordActionEvent(
        User $driver,
        JobAssignment $assignment,
        string $action,
        array $details,
    ): JobAssignmentAction {
        return $assignment->actionEvents()->create([
            'action' => $action,
            'details' => $details,
            'actor_id' => $driver->id,
            'occurred_at' => now(),
        ]);
    }

    private function ensureChronological(JobAssignment $assignment, Carbon $changedAt): void
    {
        $latestChange = JobStatusHistory::query()
            ->where('job_assignment_id', $assignment->id)
            ->max('changed_at');
        if ($latestChange !== null && $changedAt->lt(Carbon::parse($latestChange))) {
            throw ValidationException::withMessages([
                'status' => 'Assignment timestamps must not precede the latest status update.',
            ]);
        }
    }

    private function assignmentsFor(User $driver): Builder
    {
        return JobAssignment::query()
            ->with([
                'emergencyJob.ward:id,name,code',
                'fireStation:id,name,code',
                'vehicle.latestGpsLog',
                'vehicle.fireStation:id,name,code',
                'assignedDriver:id,name',
                'actionEvents',
            ])
            ->where('assigned_driver_user_id', $driver->id);
    }

    private function assignmentQuery(User $driver, string $identifier): Builder
    {
        $query = $this->assignmentsFor($driver);

        return $query->where(function (Builder $matches) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $matches->whereKey((int) $identifier)
                    ->orWhereHas('emergencyJob', fn (Builder $jobs) => $jobs->where('job_id', $identifier));

                return;
            }

            $matches->whereHas('emergencyJob', fn (Builder $jobs) => $jobs->where('job_id', $identifier));
        })->orderByDesc('assigned_at')->orderByDesc('id');
    }

    private function ensureDriver(User $driver): void
    {
        abort_unless($driver->hasRole('VEHICLE_DRIVER'), 403, 'Driver assignment endpoints are available to vehicle drivers only.');
    }
}
