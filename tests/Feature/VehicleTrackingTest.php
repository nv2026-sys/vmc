<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use App\Notifications\VehicleStatusAlert;
use App\Services\VehicleStatusWorkflow;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_assigned_driver_can_submit_gps_and_fetch_only_their_assignment_route(): void
    {
        [$dispatcher, $driver, $station, $vehicle, $assignment] = $this->activeVehicleAssignment();
        $this->actingAs($driver, 'sanctum');
        $firstRecordedAt = now()->subMinutes(2)->startOfSecond();

        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'speed_kph' => 34.5,
            'recorded_at' => $firstRecordedAt->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.latitude', 22.3072)
            ->assertJsonPath('data.assignment_id', $assignment->id);

        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3080,
            'longitude' => 73.1820,
            'recorded_at' => $firstRecordedAt->addMinute()->toIso8601String(),
        ])->assertCreated();

        $this->assertSame('22.3080000', $vehicle->fresh()->latitude);
        $this->assertSame('73.1820000', $vehicle->fresh()->longitude);
        $this->assertDatabaseHas('vehicle_gps_logs', [
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'received_by' => $driver->id,
            'latitude' => 22.3072,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'vehicles.gps_recorded',
            'user_id' => $driver->id,
        ]);

        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/gps')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.assignment_id', $assignment->id);

        $this->getJson('/api/v1/tracking')
            ->assertOk()
            ->assertJsonPath('data.0.vehicle_number', $vehicle->vehicle_number);

        $this->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonPath('data.0.vehicle.location.latitude', 22.308)
            ->assertJsonPath('data.0.vehicle.location.longitude', 73.182)
            ->assertJsonPath('data.0.job.latitude', '22.3001000')
            ->assertJsonPath('data.0.job.longitude', '73.1701000')
            ->assertJsonPath('data.0.driver.id', $driver->id);

        $this->getJson('/api/v1/fleet/dashboard')
            ->assertOk()
            ->assertJsonPath('data.0.navigation.origin.latitude', 22.308)
            ->assertJsonPath('data.0.navigation.origin.longitude', 73.182)
            ->assertJsonPath('data.0.navigation.destination.latitude', 22.3001)
            ->assertJsonPath('data.0.navigation.destination.longitude', 73.1701)
            ->assertJsonPath('data.0.navigation.destination.address', '10 Tracking Road');

        $this->getJson('/api/v1/fleet/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.location.latitude', 22.308)
            ->assertJsonPath('data.0.assignments.0.job.incident_address', '10 Tracking Road')
            ->assertJsonPath('data.0.assignments.0.job.latitude', '22.3001000')
            ->assertJsonPath('data.0.assignments.0.job.longitude', '73.1701000');
    }

    public function test_driver_cannot_submit_for_another_team_or_replay_an_older_point(): void
    {
        [, $driver, , $vehicle, $assignment] = $this->activeVehicleAssignment();
        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $this->actingAs($otherDriver, 'sanctum')
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
                'assignment_id' => $assignment->id,
                'latitude' => 22.3072,
                'longitude' => 73.1812,
            ])
            ->assertNotFound();

        $this->actingAs($driver, 'sanctum');
        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'recorded_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated();

        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3000,
            'longitude' => 73.1700,
            'recorded_at' => now()->subMinutes(2)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
    }

    public function test_driver_can_report_an_on_road_linked_vehicle_without_an_active_assignment(): void
    {
        [, $driver, , $vehicle, $assignment] = $this->activeVehicleAssignment();
        $assignment->update([
            'released_at' => now(),
            'status' => JobAssignment::STATUS_COMPLETED,
        ]);
        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
                'latitude' => 22.3090,
                'longitude' => 73.1830,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assignment_id', null)
            ->assertJsonPath('data.received_by', $driver->id);

        $this->assertSame('22.3090000', $vehicle->fresh()->latitude);
        $this->assertDatabaseHas('vehicle_gps_logs', [
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => null,
            'received_by' => $driver->id,
            'latitude' => 22.309,
        ]);
        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/gps')
            ->assertOk()
            ->assertJsonPath('data.0.assignment_id', null)
            ->assertJsonPath('data.0.received_by', $driver->id);

        app(VehicleStatusWorkflow::class)->updateForDriver(
            $driver,
            $vehicle,
            Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
        );
        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'latitude' => 22.3100,
            'longitude' => 73.1840,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_driver_road_status_changes_are_recorded_append_only_with_actor_and_timestamp(): void
    {
        [$dispatcher, $driver, , $vehicle] = $this->activeVehicleAssignment();

        $this->actingAs($driver, 'sanctum')
            ->patchJson('/api/v1/vehicles/'.$vehicle->id.'/operational-status', [
                'status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Vehicle::OPERATIONAL_STATUS_OFF_ROAD);
        $this->patchJson('/api/v1/vehicles/'.$vehicle->id.'/operational-status', [
            'status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Vehicle::OPERATIONAL_STATUS_ON_ROAD);

        $this->assertSame(Vehicle::OPERATIONAL_STATUS_ON_ROAD, $vehicle->fresh()->operational_status);
        $this->assertDatabaseCount('vehicle_status_histories', 2);
        $this->assertDatabaseHas('vehicle_status_histories', [
            'vehicle_id' => $vehicle->id,
            'previous_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            'new_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
            'changed_by' => $driver->id,
        ]);
        $this->assertDatabaseHas('vehicle_status_histories', [
            'vehicle_id' => $vehicle->id,
            'previous_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
            'new_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            'changed_by' => $driver->id,
        ]);
        $this->assertNotNull($vehicle->statusHistory()->oldest('changed_at')->value('changed_at'));
        $this->assertSame(2, $dispatcher->notifications()
            ->where('type', VehicleStatusAlert::class)
            ->count());
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $dispatcher->id,
            'type' => VehicleStatusAlert::class,
        ]);
        $this->actingAs($dispatcher)
            ->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonPath('unread', 2)
            ->assertJsonFragment([
                'event' => 'vehicle.operational_status_changed',
                'previous_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
                'new_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
            ])
            ->assertJsonFragment([
                'event' => 'vehicle.operational_status_changed',
                'previous_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
                'new_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            ]);
        $this->assertSame(0, $driver->notifications()
            ->where('type', VehicleStatusAlert::class)
            ->count());
    }

    public function test_api_logout_marks_the_drivers_linked_vehicle_off_road_before_revoking_the_token(): void
    {
        [, $driver, , $vehicle] = $this->activeVehicleAssignment();
        $token = $driver->createToken('driver-mobile')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(Vehicle::OPERATIONAL_STATUS_OFF_ROAD, $vehicle->fresh()->operational_status);
        $this->assertDatabaseHas('vehicle_status_histories', [
            'vehicle_id' => $vehicle->id,
            'previous_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            'new_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
            'changed_by' => $driver->id,
            'source' => 'logout',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $driver->id,
            'name' => 'driver-mobile',
        ]);
    }

    public function test_tracking_api_and_web_route_are_station_scoped_and_show_location_history(): void
    {
        [, , $station, $vehicle, $assignment] = $this->activeVehicleAssignment();
        VehicleGpsLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'recorded_at' => now()->subMinutes(2),
        ]);
        VehicleGpsLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'latitude' => 22.3080,
            'longitude' => 73.1820,
            'recorded_at' => now()->subMinute(),
        ]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station->users()->attach($admin->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tracking')
            ->assertOk()
            ->assertJsonPath('data.0.vehicle_number', $vehicle->vehicle_number)
            ->assertJsonPath('data.0.last_gps_at', fn ($value) => is_string($value));

        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/gps?'.http_build_query([
            'from' => now()->subDays(8)->toIso8601String(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->actingAs($admin)
            ->get(route('app.tracking', ['vehicle_id' => $vehicle->id]))
            ->assertOk()
            ->assertSee('Fleet location map')
            ->assertSee('Route history')
            ->assertSee('ops-live-map', false)
            ->assertSee('data-map-auto-refresh="15000"', false)
            ->assertSee('data-map-selected-vehicle="'.$vehicle->vehicle_number.'"', false)
            ->assertSee('data-map-vehicle="'.$vehicle->vehicle_number.'"', false)
            ->assertSee($vehicle->vehicle_number)
            ->assertSee('22.3080000');

        $this->get(route('app.tracking', ['incident_id' => $assignment->emergency_job_id]))
            ->assertOk()
            ->assertSee('Incident location map')
            ->assertSee('data-map-selected-incident="'.$assignment->emergencyJob->job_id.'"', false)
            ->assertSee('GPS-UNIT-01')
            ->assertSee('ops-live-map', false);

        $this->getJson(route('app.tracking.map-data'))
            ->assertOk()
            ->assertJsonFragment([
                'number' => 'GPS-UNIT-01',
                'status_code' => 'ASSIGNED',
                'latitude' => 22.308,
                'longitude' => 73.182,
            ])
            ->assertJsonFragment([
                'job_id' => $assignment->emergencyJob->job_id,
                'location' => '10 Tracking Road',
                'latitude' => 22.3001,
                'longitude' => 73.1701,
            ]);
    }

    /**
     * @return array{User, User, FireStation, Vehicle, JobAssignment}
     */
    private function activeVehicleAssignment(): array
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-GPS',
            'name' => 'GPS Station',
            'address' => '1 Tracking Road',
        ]);
        $station->users()->attach($dispatcher->id);
        $station->users()->attach($driver->id);
        $ward = Ward::query()->create(['code' => 'WARD-GPS', 'name' => 'GPS Ward']);
        $station->wards()->attach($ward->id);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'GPS-UNIT-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'leader_user_id' => $driver->id,
            'name' => 'GPS Response Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);
        $job = EmergencyJob::query()->create([
            'job_id' => 'VMC-GPS-'.uniqid(),
            'caller_name' => 'GPS Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subMinutes(5),
            'ward_id' => $ward->id,
            'society_name' => 'GPS Society',
            'incident_address' => '10 Tracking Road',
            'latitude' => 22.3001,
            'longitude' => 73.1701,
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $dispatcher->id,
        ]);

        $this->actingAs($dispatcher, 'sanctum')
            ->postJson('/api/v1/jobs/'.$job->id.'/assignments', [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_user_id' => $driver->id,
                    'team_id' => $team->id,
                ]],
            ])
            ->assertOk();

        return [$dispatcher, $driver, $station, $vehicle, $job->assignments()->sole()];
    }
}
