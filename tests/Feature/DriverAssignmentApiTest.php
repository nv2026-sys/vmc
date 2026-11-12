<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobRemark;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use App\Notifications\DriverAssignmentActionAlert;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_driver_assignment_workflow_records_actions_notifies_control_room_and_closes(): void
    {
        [$driver, $station, $job, $vehicle, $assignment] = $this->assignmentFixture('DRIVER-WORKFLOW-01');
        $controlRoom = User::factory()->create();
        $controlRoom->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));

        $this->actingAs($driver, 'sanctum');
        $this->getJson('/api/v1/driver/assignments/active')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', 'DRIVER-WORKFLOW-01')
            ->assertJsonPath('data.assignmentId', $assignment->id)
            ->assertJsonPath('data.status', JobAssignment::STATUS_ASSIGNED)
            ->assertJsonPath('data.destLatitude', 22.3001);

        $this->postJson('/api/v1/driver/assignments/DRIVER-WORKFLOW-01/attend', [
            'driverLatitude' => 22.3072,
            'driverLongitude' => 73.1812,
            'currentSpeedKmh' => 34.5,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_EN_ROUTE)
            ->assertJsonPath('data.latitude', 22.3072)
            ->assertJsonPath('data.currentSpeedKmh', 34.5);

        $reachedAt = now()->startOfSecond();
        $this->postJson('/api/v1/driver/assignments/'.$assignment->id.'/reached', [
            'reachedLatitude' => 22.3001,
            'reachedLongitude' => 73.1701,
            'reachedTime' => $reachedAt->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_ARRIVED)
            ->assertJsonPath('data.reachedAt', $reachedAt->toIso8601String());

        $this->postJson('/api/v1/driver/assignments/'.$assignment->id.'/report-issues', [
            'selectedIssues' => ['Narrow Alley Access', 'Low Overhead Wires'],
            'issueRemarks' => 'Use the west entrance.',
        ])
            ->assertOk()
            ->assertJsonPath('data.selectedIssues.0', 'Narrow Alley Access')
            ->assertJsonPath('data.issueRemarks', 'Use the west entrance.');

        $this->postJson('/api/v1/driver/assignments/'.$assignment->id.'/escalate', [
            'reason' => 'Additional equipment required.',
        ])
            ->assertOk()
            ->assertJsonPath('data.escalationReason', 'Additional equipment required.')
            ->assertJsonPath('data.controlRoomRecipients', 1);

        $this->postJson('/api/v1/driver/assignments/'.$assignment->id.'/need-help', [
            'reason' => 'Route is blocked.',
            'comments' => 'Request traffic assistance.',
        ])
            ->assertOk()
            ->assertJsonPath('data.needHelpReason', 'Route is blocked.')
            ->assertJsonPath('data.helpComments', 'Request traffic assistance.')
            ->assertJsonPath('data.controlRoomRecipients', 1);

        $this->getJson('/api/v1/driver/assignments/active')
            ->assertOk()
            ->assertJsonPath('data.escalationReason', 'Additional equipment required.')
            ->assertJsonPath('data.needHelpReason', 'Route is blocked.')
            ->assertJsonPath('data.selectedIssues.1', 'Low Overhead Wires')
            ->assertJsonPath('data.issueRemarks', 'Use the west entrance.')
            ->assertJsonCount(3, 'data.actionHistory');

        $completedAt = now()->startOfSecond();
        $this->postJson('/api/v1/driver/assignments/'.$assignment->id.'/complete', [
            'completionNotes' => 'Response completed safely.',
            'completedAt' => $completedAt->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_COMPLETED)
            ->assertJsonPath('data.completedAt', $completedAt->toIso8601String());

        $this->postJson('/api/v1/driver/assignments/'.$assignment->id.'/close', [
            'closeReason' => 'Returning to station.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_COMPLETED);

        $this->assertSame(EmergencyJob::STATUS_COMPLETED, $job->fresh()->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()->status);
        $this->assertDatabaseHas('vehicle_gps_logs', [
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'received_by' => $driver->id,
            'latitude' => 22.3001,
            'longitude' => 73.1701,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_IN_PROGRESS,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_COMPLETED,
        ]);
        $this->assertSame(2, $controlRoom->fresh()->notifications()
            ->where('type', DriverAssignmentActionAlert::class)
            ->count());
        $this->assertSame(5, JobRemark::query()->where('emergency_job_id', $job->id)->count());
        $this->assertDatabaseCount('job_assignment_actions', 5);
    }

    public function test_help_request_type_and_team_are_visible_on_control_room_dashboard(): void
    {
        [$driver, $station, $job, , $assignment] = $this->assignmentFixture('DRIVER-HELP-DASHBOARD');
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Rescue Team Alpha',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $assignment->update(['team_id' => $team->id]);
        $controlRoom = User::factory()->create();
        $controlRoom->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station->users()->attach($dispatcher->id);
        $otherStationDispatcher = User::factory()->create();
        $otherStationDispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $otherStation = FireStation::query()->create([
            'code' => 'OTHER-HELP-STATION',
            'name' => 'Other Help Station',
            'address' => 'Other Station Road',
        ]);
        $otherStation->users()->attach($otherStationDispatcher->id);

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/need-help', [
                'helpType' => 'AMBULANCE',
                'comments' => 'A patient needs urgent medical transport.',
            ])
            ->assertOk()
            ->assertJsonPath('data.helpType', 'AMBULANCE')
            ->assertJsonPath('data.needHelpReason', 'AMBULANCE')
            ->assertJsonPath('data.helpComments', 'A patient needs urgent medical transport.')
            ->assertJsonPath('data.controlRoomRecipients', 2);

        $notification = $controlRoom->notifications()->firstOrFail();
        $this->assertSame('Rescue Team Alpha', $notification->data['team_name']);
        $this->assertSame($driver->name, $notification->data['driver_name']);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $dispatcher->notifications()->count());
        $this->assertSame(0, $otherStationDispatcher->notifications()->count());

        $this->actingAs($dispatcher)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Driver help requests')
            ->assertSee('Ambulance')
            ->assertSee('Rescue Team Alpha')
            ->assertSee($assignment->vehicle->vehicle_number)
            ->assertSee($job->job_id)
            ->assertSee('A patient needs urgent medical transport.')
            ->assertSee('View incident');

        $this->getJson(route('dashboard.help-requests'))
            ->assertOk()
            ->assertJsonPath('data.0.help_type', 'AMBULANCE')
            ->assertJsonPath('data.0.team_name', 'Rescue Team Alpha')
            ->assertJsonPath('data.0.vehicle_number', $assignment->vehicle->vehicle_number)
            ->assertJsonPath('data.0.job_id', $job->job_id);

        $this->actingAs($otherStationDispatcher)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee($job->job_id);
    }

    public function test_driver_can_decline_only_a_pending_assignment_and_incident_returns_to_received(): void
    {
        [$driver, , $job, $vehicle, $assignment] = $this->assignmentFixture('DRIVER-DECLINE-01');
        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/decline', [
                'reason' => 'Vehicle issue.',
                'additionalNotes' => 'Please dispatch another unit.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_DECLINED)
            ->assertJsonPath('data.declineReason', "Vehicle issue.\nPlease dispatch another unit.");

        $this->assertSame(EmergencyJob::STATUS_RECEIVED, $job->fresh()->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()->status);
        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_DECLINED,
            'changed_by' => $driver->id,
        ]);
    }

    public function test_driver_assignment_routes_are_private_and_allow_status_changes_out_of_order(): void
    {
        [$driver, , , , $assignment] = $this->assignmentFixture('DRIVER-PRIVATE-01');
        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));

        $this->actingAs($otherDriver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/attend', [
                'driverLatitude' => 22.3072,
                'driverLongitude' => 73.1812,
            ])
            ->assertNotFound();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/reached', [])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_ARRIVED);

        $this->assertSame(JobAssignment::STATUS_ARRIVED, $assignment->fresh()->status);
        $this->assertSame(0, VehicleGpsLog::query()->where('vehicle_id', $assignment->vehicle_id)->count());
    }

    public function test_declining_one_assignment_does_not_reopen_an_incident_with_another_active_vehicle(): void
    {
        [$driver, $station, $job, , $assignment] = $this->assignmentFixture('DRIVER-MULTI-DECLINE-01');
        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station->users()->attach($otherDriver->id);
        $otherVehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'UNIT-'.substr(md5('DRIVER-MULTI-DECLINE-02'), 0, 8),
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_DEPLOYED,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ]);
        JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $otherVehicle->id,
            'assigned_driver_user_id' => $otherDriver->id,
            'assigned_by' => $driver->id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_EN_ROUTE,
        ]);
        $job->update(['status' => EmergencyJob::STATUS_EN_ROUTE]);

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/decline', [
                'reason' => 'Crew unavailable.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_DECLINED);

        $this->assertSame(EmergencyJob::STATUS_EN_ROUTE, $job->fresh()->status);
        $this->assertSame(JobAssignment::STATUS_EN_ROUTE, $otherVehicle->assignments()->firstOrFail()->status);
    }

    /**
     * @return array{User, FireStation, EmergencyJob, Vehicle, JobAssignment}
     */
    private function assignmentFixture(string $jobId): array
    {
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'ST-'.str_replace('-', '', $jobId),
            'name' => 'Driver API Station',
            'address' => '1 Driver Road',
        ]);
        $station->users()->attach($driver->id);
        $ward = Ward::query()->create([
            'code' => 'W-'.str_replace('-', '', $jobId),
            'name' => 'Driver API Ward',
        ]);
        $station->wards()->attach($ward->id);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'UNIT-'.substr(md5($jobId), 0, 8),
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ]);
        $job = EmergencyJob::query()->create([
            'job_id' => $jobId,
            'caller_name' => 'Driver API Caller',
            'caller_phone' => '+91-90000-01010',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $ward->id,
            'society_name' => 'Driver API Society',
            'incident_address' => '10 Driver Road',
            'latitude' => 22.3001,
            'longitude' => 73.1701,
            'status' => EmergencyJob::STATUS_ASSIGNED,
            'created_by' => $driver->id,
        ]);
        $assignment = JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_by' => $driver->id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);

        return [$driver, $station, $job, $vehicle, $assignment];
    }
}
