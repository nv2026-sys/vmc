<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleGpsLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'vehicle_id' => (int) $this->vehicle_id,
            'assignment_id' => $this->job_assignment_id === null ? null : (int) $this->job_assignment_id,
            'received_by' => $this->received_by === null ? null : (int) $this->received_by,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'speed_kph' => $this->speed_kph === null ? null : (float) $this->speed_kph,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
        ];
    }
}
