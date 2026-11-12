<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $gpsLog = $this->resource->relationLoaded('latestGpsLog')
            ? $this->latestGpsLog
            : null;

        return [
            'id' => (int) $this->id,
            'vehicle_number' => $this->vehicle_number,
            'vehicle_type' => $this->vehicle_type,
            'status' => $this->status,
            'operational_status' => $this->operational_status,
            'fire_station' => $this->whenLoaded('fireStation', fn (): ?array => $this->fireStation ? [
                'id' => (int) $this->fireStation->id,
                'code' => $this->fireStation->code,
                'name' => $this->fireStation->name,
            ] : null),
            'location' => [
                'latitude' => $gpsLog?->latitude !== null
                    ? (float) $gpsLog->latitude
                    : ($this->latitude === null ? null : (float) $this->latitude),
                'longitude' => $gpsLog?->longitude !== null
                    ? (float) $gpsLog->longitude
                    : ($this->longitude === null ? null : (float) $this->longitude),
                'recorded_at' => $gpsLog?->recorded_at?->toIso8601String(),
            ],
            'assignments' => $this->whenLoaded('assignments', fn () => JobAssignmentResource::collection($this->assignments)),
        ];
    }
}
