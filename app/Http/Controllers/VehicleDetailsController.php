<?php

namespace App\Http\Controllers;

use App\Models\EmergencyJob;
use App\Models\JobAssignment;
use App\Models\JobStatusHistory;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleDisplayStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VehicleDetailsController extends Controller
{
    private const CLOSED_JOB_STATUSES = [
        EmergencyJob::STATUS_COMPLETED,
        EmergencyJob::STATUS_DUPLICATE,
        EmergencyJob::STATUS_TRANSFERRED_TO_112,
    ];

    public function show(Request $request, int $vehicle)
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('vehicles.view'), 403);

        $vehicleQuery = Vehicle::query()->whereKey($vehicle);
        $this->scopeVehicles($vehicleQuery, $actor);
        $vehicleRecord = $vehicleQuery->with([
            'fireStation:id,name,code',
            'latestGpsLog',
        ])->firstOrFail();
        $canViewJobs = $actor->hasPermission('jobs.view');
        $assignments = $vehicleRecord->assignments()
            ->whereNull('released_at')
            ->when(
                $actor->hasRole('VEHICLE_DRIVER'),
                fn (Builder $query) => $query->where('assigned_driver_user_id', $actor->id),
            )
            ->whereHas('emergencyJob', fn (Builder $jobs) => $jobs->whereNotIn('status', self::CLOSED_JOB_STATUSES))
            ->with([
                'emergencyJob.ward:id,name,code',
                'team:id,name',
                'assignedDriver:id,name',
                'statusHistory' => fn ($history) => $history->with('actor:id,name')->orderByDesc('changed_at'),
            ])
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [JobAssignment::STATUS_QUEUED])
            ->orderByDesc('assigned_at')
            ->get();
        $closedIncidents = $canViewJobs
            ? $this->closedIncidents($vehicleRecord, $actor)
                ->withMax([
                    'statusHistory as last_closed_at' => fn (Builder $history) => $history
                        ->whereIn('new_status', self::CLOSED_JOB_STATUSES),
                ], 'changed_at')
                ->orderByDesc('last_closed_at')
                ->orderByDesc('emergency_jobs.id')
                ->paginate(10, ['emergency_jobs.*'], 'closed_page')
                ->withQueryString()
            : collect();
        $assignmentHistory = $canViewJobs
            ? JobStatusHistory::query()
                ->whereHas('assignment', function (Builder $query) use ($vehicleRecord, $actor): void {
                    $query->where('vehicle_id', $vehicleRecord->id);
                    if ($actor->hasRole('VEHICLE_DRIVER')) {
                        $query->where('assigned_driver_user_id', $actor->id);
                    }
                })
                ->with(['assignment.emergencyJob:id,job_id', 'actor:id,name'])
                ->orderByDesc('changed_at')
                ->paginate(15, ['job_status_history.*'], 'history_page')
                ->withQueryString()
            : collect();

        $vehicleHistory = $this->vehicleHistory($vehicleRecord);
        $activeAssignment = $assignments->first();
        $status = VehicleDisplayStatus::describe($vehicleRecord, $activeAssignment);
        $gpsLog = $vehicleRecord->latestGpsLog;
        $gpsIsStale = $gpsLog !== null && $gpsLog->recorded_at->lt(now()->subMinutes(5));

        return view('vehicles.show', [
            'vehicle' => $vehicleRecord,
            'status' => $status,
            'gpsLog' => $gpsLog,
            'gpsIsStale' => $gpsIsStale,
            'assignments' => $assignments,
            'closedIncidents' => $closedIncidents,
            'assignmentHistory' => $assignmentHistory,
            'vehicleHistory' => $vehicleHistory,
            'canViewJobs' => $canViewJobs,
        ]);
    }

    private function closedIncidents(Vehicle $vehicle, User $actor): Builder
    {
        return EmergencyJob::query()
            ->whereIn('status', self::CLOSED_JOB_STATUSES)
            ->whereHas('assignments', function (Builder $assignments) use ($vehicle, $actor): void {
                $assignments->where('vehicle_id', $vehicle->id);
                if ($actor->hasRole('VEHICLE_DRIVER')) {
                    $assignments->where('assigned_driver_user_id', $actor->id);
                }
            })
            ->with([
                'ward:id,name,code',
                'statusHistory' => fn ($history) => $history
                    ->whereIn('new_status', self::CLOSED_JOB_STATUSES)
                    ->orderByDesc('changed_at'),
                'assignments' => fn ($assignments) => $assignments
                    ->where('vehicle_id', $vehicle->id)
                    ->when(
                        $actor->hasRole('VEHICLE_DRIVER'),
                        fn (Builder $query) => $query->where('assigned_driver_user_id', $actor->id),
                    )
                    ->with(['team:id,name', 'assignedDriver:id,name']),
            ]);
    }

    private function vehicleHistory(Vehicle $vehicle): Collection
    {
        if (! Schema::hasTable('vehicle_status_histories')) {
            return collect();
        }

        $columns = Schema::getColumnListing('vehicle_status_histories');
        $query = DB::table('vehicle_status_histories')->where('vehicle_id', $vehicle->id);
        $query->orderByDesc(in_array('changed_at', $columns, true) ? 'changed_at' : 'id');

        return collect($query->limit(20)->get());
    }

    private function scopeVehicles(Builder $vehicles, User $actor): void
    {
        if ($actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN')) {
            return;
        }

        if ($actor->hasRole('VEHICLE_DRIVER')) {
            $vehicles->whereHas('assignments', fn (Builder $assignments) => $assignments
                ->where('assigned_driver_user_id', $actor->id));

            return;
        }

        $stationIds = $actor->fireStations()->pluck('fire_stations.id')
            ->merge($actor->managedFireStations()->pluck('id'))
            ->unique()
            ->values();
        $vehicles->whereIn('fire_station_id', $stationIds);
    }
}
