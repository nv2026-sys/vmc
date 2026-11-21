<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'fire_station_id' => (int) $this->fire_station_id,
            'fire_station' => $this->whenLoaded('fireStation', fn () => [
                'id' => (int) $this->fireStation->id,
                'name' => $this->fireStation->name,
                'code' => $this->fireStation->code,
            ]),
            'assigned_driver_user_id' => $this->assigned_driver_user_id === null
                ? null
                : (int) $this->assigned_driver_user_id,
            'assigned_driver' => $this->whenLoaded('assignedDriver', fn () => $this->assignedDriver ? [
                'id' => (int) $this->assignedDriver->id,
                'name' => $this->assignedDriver->name,
            ] : null),
            'vehicle_number' => $this->vehicle_number,
            'vehicle_type' => $this->vehicle_type,
            'registration_number' => $this->registration_number,
            'gps_device_id' => $this->gps_device_id,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'status' => $this->status,
            'operational_status' => $this->operational_status,
            'last_gps_at' => $this->whenLoaded('latestGpsLog', fn () => $this->latestGpsLog?->recorded_at?->toIso8601String()),
            'last_service_at' => $this->last_service_at,
            'created_at' => $this->created_at,
        ];
    }
}
