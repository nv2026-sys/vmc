<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Services\DriverLoginSessionManager;
use App\Services\VehicleStatusWorkflow;
use App\Services\VehicleTrackingWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function store(
        LoginRequest $request,
        AuditLogger $auditLogger,
        DriverLoginSessionManager $driverSessions,
        VehicleStatusWorkflow $statusWorkflow,
        VehicleTrackingWorkflow $trackingWorkflow,
    ): JsonResponse {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $gpsData = array_intersect_key($credentials, array_flip([
            'latitude',
            'longitude',
            'speed_kph',
            'recorded_at',
        ]));
        $newToken = DB::transaction(function () use (
            $user,
            $credentials,
            $gpsData,
            $auditLogger,
            $driverSessions,
            $statusWorkflow,
            $trackingWorkflow,
        ): NewAccessToken {
            $user = User::query()->lockForUpdate()->find($user->getKey());

            if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'email' => trans('auth.failed'),
                ]);
            }

            if ($user->hasRole('VEHICLE_DRIVER')) {
                $driverSessions->assertAvailable($user);
            }

            $user->forceFill(['last_login_at' => now()])->save();
            $newToken = $user->createToken($credentials['device_name'] ?? 'emergency-dispatch-client');
            $auditLogger->record($user, 'auth.login', $user);

            if (isset($gpsData['latitude'], $gpsData['longitude'])) {
                if (! $user->hasRole('VEHICLE_DRIVER')) {
                    throw ValidationException::withMessages([
                        'latitude' => 'Only vehicle driver accounts can submit a vehicle location at login.',
                    ]);
                }

                $vehicle = $statusWorkflow->findDriverLinkedVehicle($user, true);
                if (! $vehicle) {
                    throw ValidationException::withMessages([
                        'latitude' => 'No vehicle is linked to this driver account.',
                    ]);
                }

                $statusWorkflow->updateForDriver(
                    $user,
                    $vehicle,
                    Vehicle::OPERATIONAL_STATUS_ON_ROAD,
                );
                $vehicle->refresh();
                $trackingWorkflow->recordLocation($user, $vehicle, $gpsData);
            }

            return $newToken;
        });

        $user = $user->refresh()->load('roles.permissions');
        $vehicle = $user->hasRole('VEHICLE_DRIVER')
            ? $statusWorkflow->findDriverLinkedVehicle($user)?->load('latestGpsLog')
            : null;
        $gpsLog = $vehicle?->latestGpsLog;

        return response()->json([
            'data' => [
                'token' => $newToken->plainTextToken,
                'token_type' => 'Bearer',
                'user' => UserResource::make($user)->resolve($request),
                'vehicle' => $vehicle ? [
                    'id' => (int) $vehicle->id,
                    'vehicle_number' => $vehicle->vehicle_number,
                    'operational_status' => $vehicle->operational_status,
                    'location' => [
                        'latitude' => $gpsLog?->latitude === null
                            ? ($vehicle->latitude === null ? null : (float) $vehicle->latitude)
                            : (float) $gpsLog->latitude,
                        'longitude' => $gpsLog?->longitude === null
                            ? ($vehicle->longitude === null ? null : (float) $vehicle->longitude)
                            : (float) $gpsLog->longitude,
                        'recorded_at' => $gpsLog?->recorded_at?->toIso8601String(),
                    ],
                ] : null,
            ],
        ]);
    }

    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user()->load([
            'roles.permissions',
            'fireStations',
            'managedFireStations',
        ]));
    }

    public function destroy(
        Request $request,
        AuditLogger $auditLogger,
        DriverLoginSessionManager $driverSessions,
        VehicleStatusWorkflow $statusWorkflow,
    ): Response {
        $user = $request->user();
        $token = $user->currentAccessToken();

        DB::transaction(function () use ($user, $token, $auditLogger, $statusWorkflow): void {
            $statusWorkflow->markLinkedVehicleOffRoadOnLogout($user);

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            $auditLogger->record($user, 'auth.logout', $user);
        });

        return response()->noContent();
    }
}
