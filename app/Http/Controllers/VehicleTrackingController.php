<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRouteRequest;
use App\Models\EmergencyJob;
use App\Models\JobAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\IncidentWorkflow;
use App\Services\VehicleDisplayStatus;
use App\Services\VehicleTrackingWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class VehicleTrackingController extends Controller
{
    public function __construct(
        private readonly VehicleTrackingWorkflow $tracking,
        private readonly IncidentWorkflow $incidents,
    ) {}

    public function index(VehicleRouteRequest $request)
    {
        $validated = $request->validated();
        $vehicles = $this->tracking->trackedVehicles($request->user());
        $selectedVehicleId = isset($validated['vehicle_id']) ? (int) $validated['vehicle_id'] : null;
        $selectedIncident = null;
        if (isset($validated['incident_id'])) {
            abort_unless($request->user()->hasPermission('jobs.view'), 403);
            $selectedIncident = $this->incidents->visibleJobs($request->user())
                ->with('ward:id,name')
                ->findOrFail((int) $validated['incident_id']);
        }
        $selectedVehicle = null;
        $routeAssignments = collect();
        $route = collect();

        if ($selectedVehicleId !== null) {
            $selectedVehicle = $this->tracking->findVisibleVehicleOrFail(
                $request->user(),
                $selectedVehicleId,
            )->load('fireStation:id,name,code');
            $routeAssignments = $this->tracking->routeAssignments($request->user(), $selectedVehicle);
            $route = $this->tracking->routeHistory($request->user(), $selectedVehicle, $validated);
        }

        $mapData = $this->mapPayload($request->user(), $vehicles, $selectedIncident);
        $routePoints = $route->map(fn ($point): array => ['log' => $point]);

        return view('tracking.index', [
            'vehicles' => $vehicles,
            'mapData' => $mapData,
            'selectedVehicleId' => $selectedVehicleId,
            'mapRefreshUrl' => route('app.tracking.map-data'),
            'routePoints' => $routePoints,
            'selectedVehicle' => $selectedVehicle,
            'selectedIncident' => $selectedIncident,
            'routeAssignments' => $routeAssignments,
            'filters' => $validated,
        ]);
    }

    public function mapData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $actor = $request->user();
        $selectedIncident = null;
        if (isset($validated['incident_id'])) {
            abort_unless($actor->hasPermission('jobs.view'), 403);
            $selectedIncident = $this->incidents->visibleJobs($actor)
                ->with('ward:id,name')
                ->findOrFail((int) $validated['incident_id']);
        }

        return response()->json([
            'data' => $this->mapPayload($actor, $this->tracking->trackedVehicles($actor), $selectedIncident),
        ]);
    }

    /**
     * @return array{incidents: array<int, array<string, mixed>>, vehicles: array<int, array<string, mixed>>, center: array{0: float, 1: float}|null}
     */
    private function mapPayload(User $actor, Collection $vehicles, ?EmergencyJob $selectedIncident): array
    {
        $incidents = collect();
        $assignments = collect();
        $canViewJobs = $actor->hasPermission('jobs.view');
        foreach ($vehicles as $vehicle) {
            foreach ($vehicle->assignments as $assignment) {
                $assignments->put($assignment->emergency_job_id, [
                    'assignment' => $assignment,
                    'vehicle' => $vehicle,
                ]);
                if ($assignment->emergencyJob && $canViewJobs) {
                    $incidents->put($assignment->emergencyJob->id, $assignment->emergencyJob);
                }
            }
        }
        if ($selectedIncident) {
            $incidents->put($selectedIncident->id, $selectedIncident);
        }

        $incidentData = $incidents->map(function (EmergencyJob $job) use ($assignments): array {
            $assignmentEntry = $assignments->get($job->id);
            $assignment = $assignmentEntry['assignment'] ?? null;
            $assignedVehicle = $assignmentEntry['vehicle'] ?? null;
            $arrivedAt = $assignment?->statusHistory
                ->first(fn ($entry): bool => in_array($entry->new_status, [
                    JobAssignment::STATUS_ARRIVED,
                    JobAssignment::STATUS_STARTED,
                ], true))?->changed_at;
            $workStartedAt = $assignment?->statusHistory
                ->firstWhere('new_status', JobAssignment::STATUS_IN_PROGRESS)?->changed_at;

            return [
                'id' => (int) $job->id,
                'job_id' => $job->job_id,
                'type' => str_replace('_', ' ', $job->incident_type),
                'description' => $job->remarks ?: str_replace('_', ' ', $job->incident_type),
                'priority' => strtoupper($job->priority),
                'status' => str_replace('_', ' ', $job->status),
                'status_code' => strtoupper($job->status),
                'latitude' => $job->latitude === null ? null : (float) $job->latitude,
                'longitude' => $job->longitude === null ? null : (float) $job->longitude,
                'location' => $job->incident_address,
                'ward_id' => $job->ward_id === null ? null : (int) $job->ward_id,
                'ward' => $job->ward?->name,
                'caller_name' => $job->caller_name,
                'caller_phone' => $job->caller_phone,
                'vehicle_number' => $assignedVehicle?->vehicle_number,
                'occurred_at' => $job->occurred_at?->toIso8601String(),
                'arrived_at' => $arrivedAt?->toIso8601String(),
                'working_since' => $workStartedAt?->toIso8601String(),
                'elapsed_seconds' => $job->occurred_at ? max(0, (int) $job->occurred_at->diffInSeconds(now())) : null,
                'view_url' => route('app.jobs.show', $job),
            ];
        })->values()->all();

        $vehicleData = $vehicles->map(function (Vehicle $vehicle) use ($canViewJobs): array {
            $assignment = $vehicle->assignments->first();
            $job = $assignment?->emergencyJob;
            $gps = $vehicle->latestGpsLog;
            $latitude = $gps?->latitude ?? $vehicle->latitude;
            $longitude = $gps?->longitude ?? $vehicle->longitude;
            $status = VehicleDisplayStatus::describe($vehicle, $assignment);
            $arrivedAt = $assignment?->statusHistory
                ->first(fn ($entry): bool => in_array($entry->new_status, [
                    JobAssignment::STATUS_ARRIVED,
                    JobAssignment::STATUS_STARTED,
                ], true))?->changed_at;
            $workStartedAt = $assignment?->statusHistory->firstWhere('new_status', JobAssignment::STATUS_IN_PROGRESS)?->changed_at;

            return [
                'id' => (int) $vehicle->id,
                'number' => $vehicle->vehicle_number,
                'status_code' => $status['code'],
                'status' => $status['label'],
                'latitude' => $latitude === null ? null : (float) $latitude,
                'longitude' => $longitude === null ? null : (float) $longitude,
                'ward_id' => $canViewJobs && $job?->ward_id !== null ? (int) $job->ward_id : null,
                'ward' => $canViewJobs ? $job?->ward?->name : null,
                'incident_id' => $canViewJobs ? $job?->job_id : null,
                'destination' => $canViewJobs ? $job?->incident_address : null,
                'incident_url' => $canViewJobs && $job ? route('app.jobs.show', $job) : null,
                'incident_latitude' => $canViewJobs && $job?->latitude !== null ? (float) $job->latitude : null,
                'incident_longitude' => $canViewJobs && $job?->longitude !== null ? (float) $job->longitude : null,
                'current_location' => $latitude !== null && $longitude !== null
                    ? number_format((float) $latitude, 5).', '.number_format((float) $longitude, 5)
                    : null,
                'speed_kph' => $gps?->speed_kph === null ? null : (float) $gps->speed_kph,
                'last_gps_at' => $gps?->recorded_at?->toIso8601String(),
                'gps_stale' => $gps?->recorded_at?->lt(now()->subMinutes(5)) ?? false,
                'arrived_at' => $arrivedAt?->toIso8601String(),
                'working_since' => $workStartedAt?->toIso8601String(),
                'elapsed_seconds' => $status === 'AT_INCIDENT' && $workStartedAt
                    ? max(0, (int) $workStartedAt->diffInSeconds(now()))
                    : null,
                'view_url' => url('/vehicles/'.$vehicle->id),
            ];
        })->values()->all();

        $point = $vehicles->first(fn (Vehicle $vehicle): bool => $vehicle->latestGpsLog !== null);
        $center = $point
            ? [(float) $point->latestGpsLog->latitude, (float) $point->latestGpsLog->longitude]
            : null;

        return ['incidents' => $incidentData, 'vehicles' => $vehicleData, 'center' => $center];
    }
}
