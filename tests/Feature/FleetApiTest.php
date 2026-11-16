<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use App\Notifications\DispatchAssigned;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_driver_can_fetch_assigned_fleet_work_and_acknowledge_dispatch_notification(): void
    {
        [$dispatcher, $driver, $station, $ward, $vehicle, $team] = $this->fleetFixture();
        $job = $this->job($dispatcher, $ward, 'FLEET-ASSIGNMENT-01');

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

        $assignment = JobAssignment::query()->firstOrFail();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $driver->id,
            'type' => DispatchAssigned::class,
        ]);

        $this->actingAs($driver, 'sanctum');
        $this->getJson('/api/v1/fleet/dashboard')
            ->assertOk()
            ->assertJsonPath('data.0.job.job_id', 'FLEET-ASSIGNMENT-01')
            ->assertJsonPath('data.0.navigation.destination.address', '12 Fleet Road');

        $this->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.job.job_id', 'FLEET-ASSIGNMENT-01')
            ->assertJsonPath('data.0.job.society_name', 'Central Society')
            ->assertJsonPath('data.0.vehicle.vehicle_number', 'FLEET-FIRE-01')
            ->assertJsonPath('data.0.driver.id', $driver->id);

        $this->getJson('/api/v1/fleet/vehicles')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.vehicle_number', 'FLEET-FIRE-01')
            ->assertJsonPath('data.0.assignments.0.job.job_id', 'FLEET-ASSIGNMENT-01');

        $this->getJson('/api/v1/fleet/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.type', 'dispatch.assigned')
            ->assertJsonPath('data.0.data.assignment_id', $assignment->id)
            ->assertJsonPath('data.0.data.vehicle_number', 'FLEET-FIRE-01');

        $notificationId = $driver->fresh()->unreadNotifications()->firstOrFail()->id;
        $this->patchJson('/api/v1/fleet/notifications/'.$notificationId.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->getJson('/api/v1/fleet/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonPath('meta.unread', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_fleet_assignments_returns_all_unreleased_jobs_for_the_driver_with_pagination(): void
    {
        [, $driver, $station, $ward, $vehicle] = $this->fleetFixture();
        $vehicle->update(['operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD]);
        $secondVehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FLEET-MULTI-02-UNIT',
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_AVAILABLE,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ]);
        $jobs = collect(['FLEET-MULTI-01', 'FLEET-MULTI-02', 'FLEET-MULTI-03'])
            ->map(fn (string $jobId): EmergencyJob => $this->job(User::factory()->create(), $ward, $jobId));

        foreach ($jobs as $index => $job) {
            JobAssignment::query()->create([
                'emergency_job_id' => $job->id,
                'fire_station_id' => $station->id,
                'vehicle_id' => $index === 1 ? $secondVehicle->id : $vehicle->id,
                'assigned_driver_user_id' => $driver->id,
                'assigned_at' => now()->subMinutes($index),
                'status' => $index === 0
                    ? JobAssignment::STATUS_ASSIGNED
                    : JobAssignment::STATUS_QUEUED,
            ]);
        }

        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $otherVehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FLEET-OTHER-DRIVER-UNIT',
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $otherDriverJob = $this->job(User::factory()->create(), $ward, 'FLEET-MULTI-OTHER-DRIVER');
        JobAssignment::query()->create([
            'emergency_job_id' => $otherDriverJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $otherVehicle->id,
            'assigned_driver_user_id' => $otherDriver->id,
            'assigned_at' => now()->addMinute(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);

        $releasedJob = $this->job(User::factory()->create(), $ward, 'FLEET-MULTI-RELEASED');
        JobAssignment::query()->create([
            'emergency_job_id' => $releasedJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now()->subHour(),
            'released_at' => now()->subMinutes(30),
            'status' => JobAssignment::STATUS_COMPLETED,
        ]);

        $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/fleet/assignments?per_page=15&page=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['job_id' => 'FLEET-MULTI-01'])
            ->assertJsonFragment(['job_id' => 'FLEET-MULTI-02'])
            ->assertJsonFragment(['job_id' => 'FLEET-MULTI-03'])
            ->assertDontSee('FLEET-MULTI-RELEASED')
            ->assertDontSee('FLEET-MULTI-OTHER-DRIVER')
            ->assertJsonFragment([
                'vehicle_number' => 'FLEET-FIRE-01',
                'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            ])
            ->assertJsonFragment([
                'vehicle_number' => 'FLEET-MULTI-02-UNIT',
                'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            ]);

        $this->getJson('/api/v1/fleet/assignments?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(1, 'data');
    }

    public function test_fleet_endpoints_are_driver_only_and_notifications_are_private(): void
    {
        [, $driver, $station, $ward, $vehicle, $team] = $this->fleetFixture();
        $job = $this->job(User::factory()->create(), $ward, 'FLEET-PRIVATE-01');
        $assignment = JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'driver_user_id' => $driver->id,
            'team_id' => $team->id,
            'team_leader_user_id' => $driver->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $driver->notify(new DispatchAssigned(['assignment_id' => $assignment->id, 'job_id' => $job->job_id]));
        $notificationId = $driver->notifications()->firstOrFail()->id;

        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $this->actingAs($otherDriver, 'sanctum')
            ->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->patchJson('/api/v1/fleet/notifications/'.$notificationId.'/read')->assertNotFound();

        $operator = User::factory()->create();
        $operator->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/fleet/vehicles')
            ->assertForbidden();

        $this->getJson('/api/v1/fleet/dashboard')->assertForbidden();
    }

    public function test_driver_can_view_and_update_only_assigned_jobs_without_station_membership(): void
    {
        [$dispatcher, $driver, $station, $ward, $vehicle, $team] = $this->fleetFixture();
        $job = $this->job($dispatcher, $ward, 'FLEET-DRIVER-SCOPE-01');

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

        $station->users()->detach($driver->id);
        $assignment = JobAssignment::query()->firstOrFail();
        $unassignedJob = $this->job($dispatcher, $ward, 'FLEET-DRIVER-UNASSIGNED-01');
        $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.job_id', 'FLEET-DRIVER-SCOPE-01');

        $this->getJson(route('dashboard.activity'))
            ->assertOk()
            ->assertJsonFragment(['job_id' => 'FLEET-DRIVER-SCOPE-01'])
            ->assertDontSee($unassignedJob->job_id);

        $this->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
            'status' => JobAssignment::STATUS_EN_ROUTE,
        ])->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_EN_ROUTE);
    }

    public function test_legacy_driver_role_is_upgraded_so_driver_can_update_assignment_status(): void
    {
        [$dispatcher, $driver, $station, $ward, $vehicle, $team] = $this->fleetFixture();
        $job = $this->job($dispatcher, $ward, 'FLEET-LEGACY-DRIVER-01');

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

        $assignment = JobAssignment::query()->firstOrFail();
        $legacyRole = Role::query()->create([
            'code' => 'DRIVER',
            'name' => 'Fire Vehicle Driver',
            'is_system' => true,
        ]);
        $driver->roles()->sync([$legacyRole->id]);

        $this->seed(RbacSeeder::class);
        $this->assertTrue($driver->fresh()->hasRole('VEHICLE_DRIVER'));
        $this->assertTrue($driver->fresh()->hasPermission('jobs.update_status'));

        $this->actingAs($driver->fresh(), 'sanctum')
            ->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_EN_ROUTE);
    }

    /**
     * @return array{User, User, FireStation, Ward, Vehicle, Team}
     */
    private function fleetFixture(): array
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));

        $station = FireStation::query()->create([
            'code' => 'FS-FLEET-API',
            'name' => 'Fleet API Station',
            'address' => '1 Fleet Road',
        ]);
        $station->users()->attach([$dispatcher->id, $driver->id]);
        $ward = Ward::query()->create(['code' => 'WARD-FLEET-API', 'name' => 'Fleet API Ward']);
        $station->wards()->attach($ward->id);

        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FLEET-FIRE-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'leader_user_id' => $driver->id,
            'name' => 'Fleet API Response Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);

        return [$dispatcher, $driver, $station, $ward, $vehicle, $team];
    }

    private function job(User $creator, Ward $ward, string $jobId): EmergencyJob
    {
        return EmergencyJob::query()->create([
            'job_id' => $jobId,
            'caller_name' => 'Fleet Caller',
            'caller_phone' => '+91-90000-01000',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $ward->id,
            'society_name' => 'Central Society',
            'incident_address' => '12 Fleet Road',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $creator->id,
        ]);
    }
}
