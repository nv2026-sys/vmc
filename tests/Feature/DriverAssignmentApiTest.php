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
            ->assertJsonPath('data.incidentStatus', EmergencyJob::STATUS_ASSIGNED)
            ->assertJsonPath('data.destLatitude', 22.3001);

        $this->postJson('/api/v1/driver/assignments/DRIVER-WORKFLOW-01/attend', [
            'driverLatitude' => 22.3072,
            'driverLongitude' => 73.1812,
            'currentSpeedKmh' => 34.5,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_EN_ROUTE)
            ->assertJsonPath('data.incidentStatus', EmergencyJob::STATUS_EN_ROUTE)
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
            ->assertJsonPath('data.incidentStatus', EmergencyJob::STATUS_ARRIVED)
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
            ->assertJsonPath('data.incidentStatus', EmergencyJob::STATUS_COMPLETED)
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

    public function test_driver_all_assignments_excludes_completed_work_and_is_scoped_to_the_driver(): void
    {
        [$driver, , $activeJob, , $activeAssignment] = $this->assignmentFixture('DRIVER-ALL-ACTIVE');
        [, , , , $otherAssignment] = $this->assignmentFixture('DRIVER-ALL-OTHER');
        $historicalAssignment = $otherAssignment->replicate();
        $historicalAssignment->assigned_driver_user_id = $driver->id;
        $historicalAssignment->status = JobAssignment::STATUS_COMPLETED;
        $historicalAssignment->released_at = now();
        $historicalAssignment->save();
        $workDoneAssignment = $otherAssignment->replicate();
        $workDoneAssignment->assigned_driver_user_id = $driver->id;
        $workDoneAssignment->status = JobAssignment::STATUS_WORK_DONE;
        $workDoneAssignment->released_at = now();
        $workDoneAssignment->save();

        $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/driver/assignments/all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'assignments')
            ->assertJsonFragment([
                'assignmentId' => $activeAssignment->id,
                'status' => JobAssignment::STATUS_ASSIGNED,
            ])
            ->assertJsonMissing(['assignmentId' => $historicalAssignment->id])
            ->assertJsonMissing(['assignmentId' => $workDoneAssignment->id])
            ->assertJsonMissing([
                'assignmentId' => $otherAssignment->id,
            ]);

        $this->getJson('/api/v1/driver/assignments/all?ward_id='.$activeJob->ward_id)
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.assignmentId', $activeAssignment->id)
            ->assertJsonPath('assignments.0.wardId', $activeJob->ward_id)
            ->assertJsonPath('assignments.0.wardName', $activeJob->ward->name);

        $otherWardId = $otherAssignment->emergencyJob->ward_id;
        $this->getJson('/api/v1/driver/assignments/all?ward_id='.$otherWardId)
            ->assertOk()
            ->assertJsonCount(0, 'assignments');

        $this->getJson('/api/v1/driver/assignments/all?ward_id=not-a-ward')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ward_id');
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

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/escalate', [
                'reason' => 'Need immediate control-room guidance.',
            ])
            ->assertOk()
            ->assertJsonPath('data.controlRoomRecipients', 2);

        $this->actingAs($dispatcher)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Driver alerts')
            ->assertSee('Ambulance')
            ->assertSee('Driver escalation')
            ->assertSee('Need immediate control-room guidance.')
            ->assertSee('Rescue Team Alpha')
            ->assertSee($assignment->vehicle->vehicle_number)
            ->assertSee($job->job_id)
            ->assertSee('A patient needs urgent medical transport.')
            ->assertSee('View incident')
            ->assertSee('Statuses update every 15 seconds')
            ->assertSee('Dismiss')
            ->assertSee('Dismiss all');

        $this->getJson(route('dashboard.help-requests'))
            ->assertOk()
            ->assertJsonFragment(['action' => 'need_help', 'help_type' => 'AMBULANCE'])
            ->assertJsonFragment(['action' => 'escalated', 'title' => 'Driver escalation'])
            ->assertJsonFragment(['reason' => 'Need immediate control-room guidance.']);

        $this->actingAs($otherStationDispatcher)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee($job->job_id);
    }

    public function test_control_room_can_dismiss_its_driver_alerts_without_deleting_notifications(): void
    {
        $controlRoom = User::factory()->create();
        $controlRoom->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));
        $otherControlRoom = User::factory()->create();
        $otherControlRoom->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));

        $alert = $controlRoom->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => DriverAssignmentActionAlert::class,
            'data' => ['action' => 'need_help', 'job_id' => 'VMC-DISMISS-01'],
        ]);
        $otherAlert = $otherControlRoom->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => DriverAssignmentActionAlert::class,
            'data' => ['action' => 'need_help', 'job_id' => 'VMC-OTHER-DISMISS-01'],
        ]);
        $unrelatedNotification = $controlRoom->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => DriverAssignmentActionAlert::class,
            'data' => ['action' => 'assigned', 'job_id' => 'VMC-UNRELATED-01'],
        ]);

        $this->actingAs($controlRoom)
            ->getJson(route('dashboard.help-requests'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alert->id);

        $this->postJson(route('dashboard.help-requests.dismiss', $alert->id))
            ->assertOk()
            ->assertJsonPath('dismissed', true);

        $this->assertNotNull($alert->fresh()->read_at);
        $this->assertDatabaseHas('notifications', ['id' => $alert->id]);
        $this->assertNull($otherAlert->fresh()->read_at);
        $this->assertNull($unrelatedNotification->fresh()->read_at);

        $bulkAlert = $controlRoom->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => DriverAssignmentActionAlert::class,
            'data' => ['action' => 'escalated', 'job_id' => 'VMC-DISMISS-02'],
        ]);

        $this->postJson(route('dashboard.help-requests.dismiss-all'))
            ->assertOk()
            ->assertJsonPath('dismissed', 1);

        $this->assertNotNull($bulkAlert->fresh()->read_at);
        $this->assertNull($unrelatedNotification->fresh()->read_at);
        $this->getJson(route('dashboard.help-requests'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($otherControlRoom)
            ->postJson(route('dashboard.help-requests.dismiss', $alert->id))
            ->assertNotFound();

        $this->actingAs($otherControlRoom)
            ->postJson(route('dashboard.help-requests.dismiss', $otherAlert->id))
            ->assertOk();
    }

    public function test_dashboard_status_endpoint_returns_current_incident_and_vehicle_statuses(): void
    {
        [, , $job, $vehicle, $assignment] = $this->assignmentFixture('DASHBOARD-STATUS-01');
        $controlRoom = User::factory()->create();
        $controlRoom->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));

        $job->update(['status' => EmergencyJob::STATUS_IN_PROGRESS]);
        $assignment->update(['status' => JobAssignment::STATUS_IN_PROGRESS]);

        $this->actingAs($controlRoom)
            ->getJson(route('dashboard.statuses', [
                'incident_ids' => [$job->job_id],
                'vehicle_ids' => [$vehicle->id],
            ]))
            ->assertOk()
            ->assertJsonPath('data.incidents.0.job_id', $job->job_id)
            ->assertJsonPath('data.incidents.0.status', EmergencyJob::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.vehicles.0.id', $vehicle->id)
            ->assertJsonPath('data.vehicles.0.label', 'At Incident / On Site')
            ->assertJsonPath('data.vehicles.0.class', 'at_incident');
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

    public function test_driver_started_in_progress_and_work_done_endpoints_record_the_new_statuses(): void
    {
        [$driver, , $job, , $assignment] = $this->assignmentFixture('DRIVER-NEW-STATUSES');
        $this->actingAs($driver, 'sanctum');

        $activeAssignmentId = (int) $this->getJson('/api/v1/driver/assignments/active')
            ->assertOk()
            ->assertJsonPath('data.id', 'DRIVER-NEW-STATUSES')
            ->assertJsonPath('data.assignmentId', $assignment->id)
            ->assertJsonPath('data.status', JobAssignment::STATUS_ASSIGNED)
            ->json('data.assignmentId');

        $startedAt = now()->startOfSecond();
        $this->postJson('/api/v1/driver/assignments/'.$activeAssignmentId.'/started', [
            'reachedTime' => $startedAt->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_STARTED)
            ->assertJsonPath('data.reachedAt', $startedAt->toIso8601String());

        $this->postJson('/api/v1/driver/assignments/'.$activeAssignmentId.'/in-progress')
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_IN_PROGRESS);

        $completedAt = now()->startOfSecond();
        $this->postJson('/api/v1/driver/assignments/'.$activeAssignmentId.'/work-done', [
            'completionNotes' => 'Work completed at the incident.',
            'completedAt' => $completedAt->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_WORK_DONE)
            ->assertJsonPath('data.incidentStatus', EmergencyJob::STATUS_COMPLETED)
            ->assertJsonPath('data.completedAt', $completedAt->toIso8601String());

        $this->assertSame(EmergencyJob::STATUS_COMPLETED, $job->fresh()->status);
        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_STARTED,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_IN_PROGRESS,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_WORK_DONE,
        ]);

        $this->postJson('/api/v1/driver/assignments/'.$activeAssignmentId.'/close', [
            'closeReason' => 'Returning to station.',
        ])->assertOk()->assertJsonPath('data.status', JobAssignment::STATUS_WORK_DONE);
    }

    public function test_driver_can_prioritize_critical_queued_assignment_before_unstarted_work(): void
    {
        [$driver, , $currentJob, $vehicle, $currentAssignment] = $this->assignmentFixture('DRIVER-CRITICAL-CURRENT');
        $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);

        $criticalJob = $currentJob->replicate();
        $criticalJob->job_id = 'DRIVER-CRITICAL-QUEUED';
        $criticalJob->priority = 'CRITICAL';
        $criticalJob->save();
        $criticalAssignment = $currentAssignment->replicate();
        $criticalAssignment->emergency_job_id = $criticalJob->id;
        $criticalAssignment->assigned_at = now()->addMinute();
        $criticalAssignment->status = JobAssignment::STATUS_QUEUED;
        $criticalAssignment->save();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$criticalAssignment->id.'/attend')
            ->assertOk()
            ->assertJsonPath('data.assignmentId', $criticalAssignment->id)
            ->assertJsonPath('data.status', JobAssignment::STATUS_EN_ROUTE);

        $this->assertSame(JobAssignment::STATUS_QUEUED, $currentAssignment->fresh()->status);
        $this->assertSame(EmergencyJob::STATUS_ASSIGNED, $currentJob->fresh()->status);
        $this->assertSame(JobAssignment::STATUS_EN_ROUTE, $criticalAssignment->fresh()->status);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $currentAssignment->id,
            'previous_status' => JobAssignment::STATUS_ASSIGNED,
            'new_status' => JobAssignment::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $criticalAssignment->id,
            'previous_status' => JobAssignment::STATUS_QUEUED,
            'new_status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $criticalAssignment->id,
            'previous_status' => JobAssignment::STATUS_ASSIGNED,
            'new_status' => JobAssignment::STATUS_EN_ROUTE,
        ]);
    }

    public function test_driver_cannot_prioritize_critical_queue_if_current_assignment_has_started(): void
    {
        [$driver, , $currentJob, $vehicle, $currentAssignment] = $this->assignmentFixture('DRIVER-CRITICAL-STARTED');
        $currentAssignment->update(['status' => JobAssignment::STATUS_EN_ROUTE]);
        $currentJob->update(['status' => EmergencyJob::STATUS_EN_ROUTE]);
        $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);

        $criticalJob = $currentJob->replicate();
        $criticalJob->job_id = 'DRIVER-CRITICAL-BLOCKED';
        $criticalJob->priority = 'CRITICAL';
        $criticalJob->save();
        $criticalAssignment = $currentAssignment->replicate();
        $criticalAssignment->emergency_job_id = $criticalJob->id;
        $criticalAssignment->assigned_at = now()->addMinute();
        $criticalAssignment->status = JobAssignment::STATUS_QUEUED;
        $criticalAssignment->save();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$criticalAssignment->id.'/attend')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignment');

        $this->assertSame(JobAssignment::STATUS_EN_ROUTE, $currentAssignment->fresh()->status);
        $this->assertSame(JobAssignment::STATUS_QUEUED, $criticalAssignment->fresh()->status);
    }

    public function test_driver_cannot_prioritize_noncritical_queued_assignment(): void
    {
        [$driver, , $currentJob, , $currentAssignment] = $this->assignmentFixture('DRIVER-NONCRITICAL-CURRENT');
        $queuedJob = $currentJob->replicate();
        $queuedJob->job_id = 'DRIVER-NONCRITICAL-QUEUED';
        $queuedJob->priority = 'HIGH';
        $queuedJob->save();
        $queuedAssignment = $currentAssignment->replicate();
        $queuedAssignment->emergency_job_id = $queuedJob->id;
        $queuedAssignment->assigned_at = now()->addMinute();
        $queuedAssignment->status = JobAssignment::STATUS_QUEUED;
        $queuedAssignment->save();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$queuedAssignment->id.'/attend')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignment');

        $this->assertSame(JobAssignment::STATUS_ASSIGNED, $currentAssignment->fresh()->status);
        $this->assertSame(JobAssignment::STATUS_QUEUED, $queuedAssignment->fresh()->status);
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
