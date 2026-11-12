<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_login_returns_a_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher@example.com',
            'password' => 'a-strong-test-password',
        ]);
        $user->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => ' DISPATCHER@example.com ',
            'password' => 'a-strong-test-password',
            'device_name' => 'control-room-test',
        ]);

        $login->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'roles', 'permissions'],
                ],
            ])
            ->assertJsonPath('data.user.email', 'dispatcher@example.com');

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'dispatcher@example.com')
            ->assertJsonFragment(['jobs.assign']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.login',
        ]);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.logout',
        ]);
        $this->assertSame(2, AuditLog::query()->where('user_id', $user->id)->count());
    }

    public function test_vehicle_driver_login_records_and_returns_device_gps_as_vehicle_location(): void
    {
        $driver = User::factory()->create([
            'email' => 'driver@example.com',
            'password' => 'a-strong-test-password',
        ]);
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));

        $station = FireStation::query()->create([
            'code' => 'LOGIN-GPS-STATION',
            'name' => 'Login GPS Station',
            'address' => '1 Main Street',
        ]);
        $ward = Ward::query()->create(['code' => 'LOGIN-GPS-WARD', 'name' => 'Login GPS Ward']);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'LOGIN-GPS-UNIT',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ]);
        $job = EmergencyJob::query()->create([
            'job_id' => 'LOGIN-GPS-INCIDENT',
            'caller_name' => 'GPS Test Caller',
            'caller_phone' => '+91-90000-01010',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'NORMAL',
            'occurred_at' => now(),
            'ward_id' => $ward->id,
            'incident_address' => 'Login GPS Location',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $driver->id,
        ]);
        JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);

        $recordedAt = now()->startOfSecond();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'driver@example.com',
            'password' => 'a-strong-test-password',
            'device_name' => 'driver-app',
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'recorded_at' => $recordedAt->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.vehicle.id', $vehicle->id)
            ->assertJsonPath('data.vehicle.vehicle_number', 'LOGIN-GPS-UNIT')
            ->assertJsonPath('data.vehicle.location.latitude', 22.3072)
            ->assertJsonPath('data.vehicle.location.longitude', 73.1812)
            ->assertJsonPath('data.vehicle.location.recorded_at', $recordedAt->toIso8601String());

        $token = $login->json('data.token');
        $this->withToken($token)
            ->getJson('/api/v1/fleet/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.location.latitude', 22.3072)
            ->assertJsonPath('data.0.location.longitude', 73.1812)
            ->assertJsonPath('data.0.location.recorded_at', $recordedAt->toIso8601String());

        $this->withToken($token)
            ->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonPath('data.0.vehicle.location.latitude', 22.3072)
            ->assertJsonPath('data.0.vehicle.location.longitude', 73.1812)
            ->assertJsonPath('data.0.navigation.origin.latitude', 22.3072)
            ->assertJsonPath('data.0.navigation.origin.longitude', 73.1812);

        $this->assertSame('22.3072000', $vehicle->fresh()->latitude);
        $this->assertSame('73.1812000', $vehicle->fresh()->longitude);
        $this->assertDatabaseHas('vehicle_gps_logs', [
            'vehicle_id' => $vehicle->id,
            'received_by' => $driver->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
        ]);
        $this->assertSame(1, VehicleGpsLog::query()->where('vehicle_id', $vehicle->id)->count());
    }

    public function test_invalid_credentials_and_inactive_accounts_are_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'dispatcher@example.com',
            'password' => 'a-strong-test-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dispatcher@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dispatcher@example.com',
            'password' => 'a-strong-test-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_validates_inputs_and_protected_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => ['not', 'an', 'email'],
            'password' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_browser_requests_to_unauthenticated_api_routes_receive_json_401(): void
    {
        $this->get('/api/v1/auth/me', ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_api_requests_reject_and_revoke_tokens_for_deactivated_accounts(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_throttle_limits_repeated_attempts_for_an_account(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_login_throttle_limits_password_spraying_across_account_names(): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'spray-'.$attempt.'@example.com',
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'another-account@example.com',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_security_headers_are_added_and_hsts_is_limited_to_https(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
