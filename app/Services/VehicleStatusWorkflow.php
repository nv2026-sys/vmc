<?php

namespace App\Services;

use App\Models\JobAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleStatusHistory;
use App\Notifications\VehicleStatusAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class VehicleStatusWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly IncidentWorkflow $incidentWorkflow,
    ) {}

    public function updateForDriver(User $actor, Vehicle $requestedVehicle, string $status): ?VehicleStatusHistory
    {
        abort_unless($actor->hasRole('VEHICLE_DRIVER'), 403);

        return DB::transaction(function () use ($actor, $requestedVehicle, $status): ?VehicleStatusHistory {
            $vehicle = $this->findDriverLinkedVehicle($actor, true);
            abort_unless($vehicle && (int) $vehicle->id === (int) $requestedVehicle->id, 404);

            $history = $this->setStatus($actor, $vehicle, $status, 'api');
            if ($status === Vehicle::OPERATIONAL_STATUS_ON_ROAD) {
                $this->incidentWorkflow->activateQueuedAssignmentsForVehicle($actor, $vehicle);
            }

            return $history;
        });
    }

    public function markLinkedVehicleOffRoadOnLogout(User $actor): ?VehicleStatusHistory
    {
        if (! $actor->hasRole('VEHICLE_DRIVER')) {
            return null;
        }

        return DB::transaction(function () use ($actor): ?VehicleStatusHistory {
            $vehicle = $this->findDriverLinkedVehicle($actor, true);
            if (! $vehicle) {
                return null;
            }

            return $this->setStatus(
                $actor,
                $vehicle,
                Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
                'logout',
                true,
            );
        });
    }

    public function findDriverLinkedVehicle(User $driver, bool $lock = false): ?Vehicle
    {
        $assignment = JobAssignment::query()
            ->where('assigned_driver_user_id', $driver->id)
            ->whereNull('released_at')
            ->where('status', '!=', JobAssignment::STATUS_QUEUED)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->first();

        $assignment ??= JobAssignment::query()
            ->where('assigned_driver_user_id', $driver->id)
            ->where('status', '!=', JobAssignment::STATUS_QUEUED)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->first();

        if (! $assignment) {
            return null;
        }

        $vehicleQuery = Vehicle::query()->whereKey($assignment->vehicle_id);
        if ($lock) {
            $vehicleQuery->lockForUpdate();
        }

        return $vehicleQuery->first();
    }

    private function setStatus(
        User $actor,
        Vehicle $vehicle,
        string $status,
        string $source,
        bool $recordUnchanged = false,
    ): ?VehicleStatusHistory {
        $previousStatus = $vehicle->operational_status;
        if ($previousStatus === $status && ! $recordUnchanged) {
            return null;
        }

        $vehicle->update(['operational_status' => $status]);
        $history = $vehicle->statusHistory()->create([
            'previous_status' => $previousStatus,
            'new_status' => $status,
            'changed_by' => $actor->id,
            'changed_at' => now(),
            'source' => $source,
        ]);

        $this->auditLogger->record($actor, 'vehicles.road_status_changed', $vehicle, [
            'previous_status' => $previousStatus,
            'new_status' => $status,
            'source' => $source,
        ]);

        if ($previousStatus !== $status) {
            $vehicle->loadMissing('fireStation:id,name');
            User::query()
                ->where('is_active', true)
                ->whereKeyNot($actor->id)
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->whereHas('permissions', fn (Builder $permissions) => $permissions
                        ->where('permissions.code', 'dashboard.view')))
                ->whereHas('roles', fn (Builder $roles) => $roles
                    ->whereHas('permissions', fn (Builder $permissions) => $permissions
                        ->where('permissions.code', 'vehicles.view')))
                ->where(fn (Builder $users) => $users
                    ->whereHas('roles', fn (Builder $roles) => $roles
                        ->whereIn('roles.code', ['SUPER_ADMIN', 'CONTROL_ROOM_ADMIN']))
                    ->orWhereHas('fireStations', fn (Builder $stations) => $stations
                        ->whereKey($vehicle->fire_station_id))
                    ->orWhereHas('managedFireStations', fn (Builder $stations) => $stations
                        ->whereKey($vehicle->fire_station_id)))
                ->get()
                ->each(fn (User $recipient) => $recipient->notify(new VehicleStatusAlert([
                    'event' => 'vehicle.operational_status_changed',
                    'vehicle_id' => (int) $vehicle->id,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'station_name' => $vehicle->fireStation?->name,
                    'previous_status' => $previousStatus,
                    'new_status' => $status,
                    'changed_by' => $actor->name,
                    'source' => $source,
                    'message' => $actor->name.' changed vehicle '.$vehicle->vehicle_number.' from '
                        .str_replace('_', ' ', strtolower((string) $previousStatus)).' to '
                        .str_replace('_', ' ', strtolower($status)).'.',
                    'changed_at' => $history->changed_at->toIso8601String(),
                ])));
        }

        return $history;
    }
}
