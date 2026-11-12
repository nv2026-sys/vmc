<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $vehicleLocation = static function (?Vehicle $vehicle): array {
            $gpsLog = $vehicle?->latestGpsLog;

            return [
                'latitude' => $gpsLog?->latitude !== null
                    ? (float) $gpsLog->latitude
                    : ($vehicle?->latitude === null ? null : (float) $vehicle->latitude),
                'longitude' => $gpsLog?->longitude !== null
                    ? (float) $gpsLog->longitude
                    : ($vehicle?->longitude === null ? null : (float) $vehicle->longitude),
                'recorded_at' => $gpsLog?->recorded_at?->toIso8601String(),
            ];
        };

        return [
            'id' => (int) $this->id,
            'fire_station' => $this->whenLoaded('fireStation', fn (): ?array => $this->fireStation ? [
                'id' => (int) $this->fireStation->id,
                'code' => $this->fireStation->code,
                'name' => $this->fireStation->name,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn (): ?array => $this->vehicle ? [
                'id' => (int) $this->vehicle->id,
                'vehicle_number' => $this->vehicle->vehicle_number,
                'vehicle_type' => $this->vehicle->vehicle_type,
                'operational_status' => $this->vehicle->operational_status,
                'location' => $vehicleLocation($this->vehicle),
            ] : null),
            'team' => $this->whenLoaded('team', fn (): ?array => $this->team ? [
                'id' => (int) $this->team->id,
                'name' => $this->team->name,
            ] : null),
            'team_leader' => $this->whenLoaded('teamLeader', fn (): ?array => $this->teamLeader ? [
                'id' => (int) $this->teamLeader->id,
                'name' => $this->teamLeader->name,
            ] : null),
            'driver' => $this->whenLoaded('assignedDriver', fn (): ?array => $this->assignedDriver ? [
                'id' => (int) $this->assignedDriver->id,
                'name' => $this->assignedDriver->name,
            ] : null),
            'status' => $this->status,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'remarks' => $this->remarks,
            'job' => $this->whenLoaded('emergencyJob', fn () => EmergencyJobResource::make(
                $this->emergencyJob?->loadMissing('ward'),
            )),
            'navigation' => $this->when(
                $this->relationLoaded('vehicle') && $this->relationLoaded('emergencyJob'),
                function () use ($vehicleLocation): array {
                    $vehicle = $this->vehicle;
                    $job = $this->emergencyJob;

                    return [
                        'origin' => $vehicleLocation($vehicle),
                        'destination' => [
                            'latitude' => $job?->latitude === null ? null : (float) $job->latitude,
                            'longitude' => $job?->longitude === null ? null : (float) $job->longitude,
                            'address' => $job?->incident_address,
                        ],
                    ];
                },
            ),
        ];
    }
}
