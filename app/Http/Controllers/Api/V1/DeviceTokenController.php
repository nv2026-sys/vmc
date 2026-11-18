<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token' => ['required', 'string', 'max:4096'],
            'device_type' => ['required', 'string', 'in:android,ios'],
        ]);
        $tokenHash = hash('sha256', $validated['fcm_token']);

        $device = UserDevice::query()->updateOrCreate(
            ['fcm_token_hash' => $tokenHash],
            [
                'user_id' => $request->user()->id,
                'fcm_token' => $validated['fcm_token'],
                'device_type' => $validated['device_type'],
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'FCM device token saved successfully.',
            'data' => [
                'id' => $device->id,
                'device_type' => $device->device_type,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
        ]);
    }
}
