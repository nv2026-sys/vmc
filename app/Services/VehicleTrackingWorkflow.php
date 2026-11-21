<?php

namespace App\Services;

use App\Models\JobAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleTrackingWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly VehicleStatusWorkflow $statusWorkflow,
    ) {}

    public function trackedVehicles(User $actor): Collection
    {
        return $this->visibleVehicles($actor)
            ->whereHas('gpsLogs')
            ->with([
                'fireStation:id,name,code',
                'latestGpsLog',
                'assignments' => function ($assignments) use ($actor): void {
                    $assignments->whereNull('released_at')
                        ->where('status', '!=', JobAssignment::STATUS_QUEUED)
                        ->latest('assigned_at')
                        ->with([
                            'emergencyJob.ward:id,name',
                            'statusHistory' => fn ($history) => $history
                                ->whereIn('new_status', [
                                    JobAssignment::STATUS_ARRIVED,
                                    JobAssignment::STATUS_STARTED,
                                    JobAssignment::STATUS_IN_PROGRESS,
                                ])
                                ->orderBy('changed_at'),
                        ]);
                    if ($this->isDriverRestricted($actor)) {
                        $assignments->where('assigned_driver_user_id', $actor->id);
                    }
                },
            ])
            ->orderBy('vehicle_number')
            ->get();
    }

    public function findVisibleVehicleOrFail(User $actor, int $vehicleId): Vehicle
    {
        return $this->visibleVehicles($actor)->findOrFail($vehicleId);
    }

    public function routeAssignments(User $actor, Vehicle $vehicle): Collection
    {
        $vehicle = $this->findVisibleVehicleOrFail($actor, $vehicle->id);

        return $this->isDriverRestricted($actor)
            ? $this->driverAssignments($actor, $vehicle)->load('emergencyJob:id,job_id')->sortByDesc('assigned_at')->values()
            : $vehicle->assignments()->with('emergencyJob:id,job_id')->orderByDesc('assigned_at')->get();
    }

    public function routeHistory(
        User $actor,
        Vehicle $vehicle,
        array $filters = [],
    ): Collection {
        $vehicle = $this->findVisibleVehicleOrFail($actor, $vehicle->id);
        $query = $vehicle->gpsLogs()->with('assignment.emergencyJob:id,job_id');

        if ($this->isDriverRestricted($actor)) {
            $assignmentIds = $this->driverAssignments($actor, $vehicle)->pluck('id');
            $query->where(function ($logs) use ($assignmentIds, $actor): void {
                $logs->whereIn('job_assignment_id', $assignmentIds)
                    ->orWhere('received_by', $actor->id);
            });
        }

        if (! empty($filters['assignment_id'])) {
            $assignment = $vehicle->assignments()->find($filters['assignment_id']);
            abort_unless($assignment, 404);
            if ($this->isDriverRestricted($actor)) {
                abort_unless($assignment->released_at === null
                    && $assignment->status !== JobAssignment::STATUS_QUEUED
                    && $this->driverAssignments($actor, $vehicle)->contains('id', $assignment->id), 404);
            }

            $query->where('job_assignment_id', $assignment->id);
        }

        $query
            ->when(
                ! empty($filters['from']),
                fn ($logs) => $logs->where('recorded_at', '>=', $filters['from']),
                fn ($logs) => $logs->where('recorded_at', '>=', now()->subDay()),
            )
            ->when(! empty($filters['to']), fn ($logs) => $logs->where('recorded_at', '<=', $filters['to']))
            ->orderByDesc('recorded_at')
            ->limit((int) ($filters['limit'] ?? 500));

        return $query->get()->sortBy('recorded_at')->values();
    }

    public function recordLocation(User $actor, Vehicle $vehicle, array $data): VehicleGpsLog
    {
        return DB::transaction(function () use ($actor, $vehicle, $data): VehicleGpsLog {
            $vehicle = $this->visibleVehicles($actor)->lockForUpdate()->findOrFail($vehicle->id);
            if ($this->isDriverRestricted($actor)) {
                $linkedVehicle = $this->statusWorkflow->findDriverLinkedVehicle($actor, true);
                abort_unless(
                    (int) $vehicle->assigned_driver_user_id === (int) $actor->id
                        || ($linkedVehicle && (int) $linkedVehicle->id === (int) $vehicle->id),
                    404,
                );
                if ($vehicle->operational_status !== Vehicle::OPERATIONAL_STATUS_ON_ROAD) {
                    throw ValidationException::withMessages([
                        'status' => 'The assigned vehicle must be On Road to submit GPS updates.',
                    ]);
                }
            }

            $assignment = null;
            if (! empty($data['assignment_id'])) {
                $assignment = $vehicle->assignments()
                    ->whereKey($data['assignment_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $assignment || ! $this->canSubmitForAssignment($actor, $assignment)) {
                    throw ValidationException::withMessages([
                        'assignment_id' => 'The assignment must belong to this vehicle and your driver account.',
                    ]);
                }
            } elseif (! $actor->hasRole('VEHICLE_DRIVER')
                && ! $actor->hasRole('SUPER_ADMIN')
                && ! $actor->hasRole('CONTROL_ROOM_ADMIN')) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'A valid assignment is required for this account.',
                ]);
            }

            $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();
            $latestRecordedAt = $vehicle->gpsLogs()->max('recorded_at');
            if ($latestRecordedAt !== null && $recordedAt->lt($latestRecordedAt)) {
                throw ValidationException::withMessages([
                    'recorded_at' => 'GPS updates must not be older than the latest point already recorded.',
                ]);
            }

            $log = $vehicle->gpsLogs()->create([
                'job_assignment_id' => $assignment?->id,
                'received_by' => $actor->id,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'speed_kph' => $data['speed_kph'] ?? null,
                'recorded_at' => $recordedAt,
            ]);

            $vehicle->update([
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);

            $this->auditLogger->record($actor, 'vehicles.gps_recorded', $log, [
                'vehicle_id' => $vehicle->id,
                'assignment_id' => $assignment?->id,
            ]);

            return $log->load('vehicle:id,vehicle_number', 'assignment:id,emergency_job_id');
        });
    }

    private function canSubmitForAssignment(User $actor, JobAssignment $assignment): bool
    {
        if ($actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN')) {
            return true;
        }

        if (! $actor->hasRole('VEHICLE_DRIVER')) {
            return false;
        }

        return $assignment->released_at === null
            && $assignment->status !== JobAssignment::STATUS_QUEUED
            && (int) $assignment->assigned_driver_user_id === (int) $actor->getKey();
    }

    private function visibleVehicles(User $actor): Builder
    {
        $vehicles = Vehicle::query();
        if ($actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN')) {
            return $vehicles;
        }

        if ($this->isDriverRestricted($actor)) {
            $vehicles->where(function (Builder $query) use ($actor): void {
                $query->where('assigned_driver_user_id', $actor->id)
                    ->orWhereHas('assignments', fn (Builder $assignments) => $assignments
                        ->where('assigned_driver_user_id', $actor->id));
            });
        } else {
            $stationIds = $actor->fireStations()->pluck('fire_stations.id')
                ->merge($actor->managedFireStations()->pluck('id'))
                ->unique()
                ->values();
            $vehicles->whereIn('fire_station_id', $stationIds);
        }

        return $vehicles;
    }

    private function driverAssignments(User $actor, Vehicle $vehicle): Collection
    {
        return $vehicle->assignments()
            ->where('assigned_driver_user_id', $actor->id)
            ->whereNull('released_at')
            ->where('status', '!=', JobAssignment::STATUS_QUEUED)
            ->get(['id', 'vehicle_id', 'team_id', 'team_leader_user_id', 'assigned_driver_user_id']);
    }

    private function isDriverRestricted(User $actor): bool
    {
        return ! $actor->hasRole('SUPER_ADMIN')
            && ! $actor->hasRole('CONTROL_ROOM_ADMIN')
            && $actor->hasRole('VEHICLE_DRIVER');
    }
}
