<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateVehicleOperationalStatusRequest;
use App\Models\Vehicle;
use App\Services\VehicleStatusWorkflow;
use Illuminate\Http\JsonResponse;

class VehicleOperationalStatusController extends Controller
{
    public function __construct(private readonly VehicleStatusWorkflow $statusWorkflow) {}

    public function update(UpdateVehicleOperationalStatusRequest $request, Vehicle $vehicle): JsonResponse
    {
        $history = $this->statusWorkflow->updateForDriver(
            $request->user(),
            $vehicle,
            $request->validated('status'),
        );

        return response()->json([
            'data' => [
                'vehicle_id' => (int) $vehicle->id,
                'status' => $vehicle->fresh()->operational_status,
                'changed_at' => $history?->changed_at?->toIso8601String(),
            ],
        ]);
    }
}
