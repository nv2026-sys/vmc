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
use App\Models\Ward;
use App\Notifications\DispatchAssigned;
use App\Notifications\DriverRemarkReplyAlert;
use App\Notifications\JobAssignmentAlert;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

class IncidentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function driverAt(FireStation $station): User
    {
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station->users()->attach($driver->id);

        return $driver;
    }

    public function test_dispatcher_can_set_any_assignment_status_and_reopen_completed_units(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $vehicleOne = $this->vehicle($station, 'VMC-FIRE-01');
        $vehicleTwo = $this->vehicle($station, 'VMC-FIRE-02');
        $driverOne = $this->driverAt($station);
        $driverTwo = $this->driverAt($station);
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Central Response Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);
        $this->actingAs($operator, 'sanctum');
        $job = $this->postJson('/api/v1/jobs', $this->incidentData($ward))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/jobs/'.$job['id'].'/assignments', [
            'assignments' => [
                ['fire_station_id' => $station->id, 'vehicle_id' => $vehicleOne->id, 'driver_user_id' => $driverOne->id, 'team_id' => $team->id],
                ['fire_station_id' => $station->id, 'vehicle_id' => $vehicleTwo->id, 'driver_user_id' => $driverTwo->id],
            ],
            'remarks' => 'Initial dispatch',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_ASSIGNED)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_ASSIGNED)
            ->assertJsonPath('data.status_history.1.assignment.vehicle.vehicle_number', 'VMC-FIRE-01');

        $assignmentIds = JobAssignment::query()->orderBy('id')->pluck('id')->all();
        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[0].'/status', [
            'status' => JobAssignment::STATUS_ARRIVED,
        ])->assertOk()->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_ARRIVED);
        $this->assertSame(0, $driverOne->notifications()
            ->where('type', DriverRemarkReplyAlert::class)
            ->count());

        $statusFlows = [
            ['started', 'reached', 'in progress'],
            [
                JobAssignment::STATUS_EN_ROUTE,
                JobAssignment::STATUS_ARRIVED,
                JobAssignment::STATUS_IN_PROGRESS,
            ],
        ];
        foreach ($assignmentIds as $index => $assignmentId) {
            foreach ($statusFlows[$index] as $status) {
                $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentId.'/status', [
                    'status' => $status,
                    'remarks' => 'Status confirmed',
                    'latitude' => 22.3072,
                    'longitude' => 73.1812,
                ])->assertOk();
            }
        }

        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[0].'/status', [
            'status' => JobAssignment::STATUS_COMPLETED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_IN_PROGRESS);

        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[1].'/status', [
            'status' => JobAssignment::STATUS_COMPLETED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_COMPLETED);

        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[0].'/status', [
            'status' => JobAssignment::STATUS_ASSIGNED,
            'remarks' => 'Reopened after completion.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_ASSIGNED);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $driverOne->id,
            'type' => DriverRemarkReplyAlert::class,
        ]);
        $remarkReply = $driverOne->notifications()
            ->where('type', DriverRemarkReplyAlert::class)
            ->get()
            ->first(fn (DatabaseNotification $notification): bool => $notification->data['reply'] === 'Reopened after completion.');
        $this->assertNotNull($remarkReply);
        $this->assertSame('assignment.remark_reply', $remarkReply->data['event']);
        $this->assertSame($job['job_id'], $remarkReply->data['job_id']);
        $this->actingAs($driverOne)
            ->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonFragment([
                'event' => 'assignment.remark_reply',
                'message' => 'New response on incident '.$job['job_id'].': Reopened after completion.',
            ]);
        $this->assertNull(JobAssignment::query()->findOrFail($assignmentIds[0])->released_at);
        $this->assertSame(Vehicle::STATUS_DEPLOYED, $vehicleOne->fresh()->status);
        $this->assertSame(Team::STATUS_DEPLOYED, $team->fresh()->status);

        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[0].'/status', [
            'status' => JobAssignment::STATUS_COMPLETED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_COMPLETED);

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicleOne->fresh()->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicleTwo->fresh()->status);
        $this->assertSame(Team::STATUS_AVAILABLE, $team->fresh()->status);
        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job['id'],
            'job_assignment_id' => $assignmentIds[0],
            'new_status' => JobAssignment::STATUS_EN_ROUTE,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.assignment_status_updated',
            'subject_id' => (string) $assignmentIds[0],
        ]);
    }

    public function test_dispatcher_cannot_assign_resources_from_an_unmanaged_station(): void
    {
        [$operator, , $ward] = $this->operatorWithStation();
        $outsideStation = FireStation::query()->create([
            'code' => 'FS-OUTSIDE',
            'name' => 'Outside Station',
            'address' => 'Outside scope',
        ]);
        $outsideStation->wards()->attach($ward->id);
        $outsideVehicle = $this->vehicle($outsideStation, 'OUTSIDE-01');
        $outsideDriver = $this->driverAt($outsideStation);
        $this->actingAs($operator, 'sanctum');
        $job = $this->postJson('/api/v1/jobs', $this->incidentData($ward))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/jobs/'.$job['id'].'/assignments', [
            'assignments' => [[
                'fire_station_id' => $outsideStation->id,
                'vehicle_id' => $outsideVehicle->id,
                'driver_user_id' => $outsideDriver->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');

        $this->assertDatabaseCount('job_assignments', 0);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $outsideVehicle->fresh()->status);
    }

    public function test_assignment_and_driver_status_changes_notify_each_relevant_user(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station->users()->attach($stationAdmin->id);
        $driver = $this->driverAt($station);
        $vehicle = $this->vehicle($station, 'VMC-NOTIFICATION-01');
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Notification Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);
        $job = $this->assignedJob($operator, $ward, 'VMC-NOTIFICATION-JOB');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/jobs/{$job->id}/assignments", [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_user_id' => $driver->id,
                    'team_id' => $team->id,
                ]],
            ])
            ->assertOk();

        $assignment = JobAssignment::query()->sole();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $driver->id,
            'type' => DispatchAssigned::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $stationAdmin->id,
            'type' => JobAssignmentAlert::class,
        ]);

        $this->actingAs($driver, 'sanctum')
            ->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
            ])
            ->assertOk();

        $this->assertTrue($operator->notifications()
            ->where('type', JobAssignmentAlert::class)
            ->get()
            ->contains(fn (DatabaseNotification $notification): bool => ($notification->data['event'] ?? null) === 'assignment.status_changed'
                && ($notification->data['new_status'] ?? null) === JobAssignment::STATUS_EN_ROUTE));
        $this->assertTrue($stationAdmin->notifications()
            ->where('type', JobAssignmentAlert::class)
            ->get()
            ->contains(fn (DatabaseNotification $notification): bool => ($notification->data['event'] ?? null) === 'assignment.status_changed'
                && ($notification->data['new_status'] ?? null) === JobAssignment::STATUS_EN_ROUTE));
        $this->assertFalse($driver->notifications()
            ->where('type', JobAssignmentAlert::class)
            ->exists());
    }

    public function test_started_and_finished_status_aliases_are_normalized_for_the_api(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $job = $this->assignedJob($operator, $ward, 'VMC-STATUS-ALIASES');
        $assignment = $this->assignment($station, $job, 'STATUS-ALIASES-01', null, $driver);

        $this->actingAs($driver, 'sanctum')
            ->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
                'status' => 'started',
            ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_EN_ROUTE);

        $this->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
            'status' => 'finished',
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_COMPLETED);

        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $assignment->id,
            'new_status' => JobAssignment::STATUS_COMPLETED,
        ]);
    }

    public function test_one_vehicle_can_serve_multiple_incidents_but_not_be_duplicated_for_one_active_incident(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $vehicle = $this->vehicle($station, 'VMC-MULTI-INCIDENT-01');
        $driver = $this->driverAt($station);
        $firstJob = $this->assignedJob($operator, $ward, 'VMC-MULTI-INCIDENT-A');
        $secondJob = $this->assignedJob($operator, $ward, 'VMC-MULTI-INCIDENT-B');
        $this->actingAs($operator, 'sanctum');

        foreach ([$firstJob, $secondJob] as $job) {
            $this->postJson("/api/v1/jobs/{$job->id}/assignments", [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_user_id' => $driver->id,
                ]],
            ])->assertOk();
        }

        $this->postJson("/api/v1/jobs/{$firstJob->id}/assignments", [
            'assignments' => [[
                'fire_station_id' => $station->id,
                'vehicle_id' => $vehicle->id,
                'driver_user_id' => $driver->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');

        $this->assertDatabaseCount('job_assignments', 2);
        $this->assertSame(Vehicle::STATUS_DEPLOYED, $vehicle->fresh()->status);
    }

    public function test_driver_with_an_active_assignment_cannot_be_assigned_to_a_different_vehicle(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $activeJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-ACTIVE-VEHICLE');
        $this->assignment($station, $activeJob, 'VMC-DRIVER-ACTIVE-VEHICLE-01', null, $driver);
        $newVehicle = $this->vehicle($station, 'VMC-DRIVER-ACTIVE-VEHICLE-02');
        $newJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-SECOND-VEHICLE');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/jobs/{$newJob->id}/assignments", [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $newVehicle->id,
                    'driver_user_id' => $driver->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.assignments.0',
                'A busy vehicle and driver can only be queued together as the same pair.',
            );

        $this->assertDatabaseCount('job_assignments', 1);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $newVehicle->fresh()->status);
    }

    public function test_busy_vehicle_driver_pair_and_team_are_queued_and_activated_in_order(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $vehicle = $this->vehicle($station, 'VMC-QUEUE-01');
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Queued Response Team',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $activeJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-ACTIVE');
        $activeAssignment = JobAssignment::query()->create([
            'emergency_job_id' => $activeJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'team_id' => $team->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_by' => $operator->id,
            'assigned_at' => now()->subMinutes(2),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $vehicle->update([
            'status' => Vehicle::STATUS_DEPLOYED,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ]);

        $otherDriver = $this->driverAt($station);
        $firstQueuedJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-NEXT-01');
        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/jobs/'.$firstQueuedJob->id.'/assignments', [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_user_id' => $otherDriver->id,
                    'team_id' => $team->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $firstQueuedResponse = $this->postJson('/api/v1/jobs/'.$firstQueuedJob->id.'/assignments', [
            'assignments' => [[
                'fire_station_id' => $station->id,
                'vehicle_id' => $vehicle->id,
                'driver_user_id' => $driver->id,
                'team_id' => $team->id,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_QUEUED);
        $firstQueuedId = $firstQueuedResponse->json('data.assignments.0.id');
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $driver->id,
            'type' => JobAssignmentAlert::class,
        ]);
        $this->assertSame(1, $driver->notifications()
            ->where('type', JobAssignmentAlert::class)
            ->where('data->event', 'assignment.queued')
            ->count());

        $secondQueuedJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-NEXT-02');
        $secondQueuedResponse = $this->postJson('/api/v1/jobs/'.$secondQueuedJob->id.'/assignments', [
            'assignments' => [[
                'fire_station_id' => $station->id,
                'vehicle_id' => $vehicle->id,
                'driver_user_id' => $driver->id,
                'team_id' => $team->id,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_QUEUED);
        $secondQueuedId = $secondQueuedResponse->json('data.assignments.0.id');

        $teamQueuedVehicle = $this->vehicle($station, 'VMC-QUEUE-TEAM-ONLY');
        $teamQueuedDriver = $this->driverAt($station);
        $teamQueuedJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-TEAM-ONLY');
        $teamQueuedResponse = $this->postJson('/api/v1/jobs/'.$teamQueuedJob->id.'/assignments', [
            'assignments' => [[
                'fire_station_id' => $station->id,
                'vehicle_id' => $teamQueuedVehicle->id,
                'driver_user_id' => $teamQueuedDriver->id,
                'team_id' => $team->id,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_QUEUED);
        $teamQueuedId = $teamQueuedResponse->json('data.assignments.0.id');

        $driverAssignments = $this->actingAs($driver, 'sanctum')
            ->getJson('/api/v1/driver/assignments/active')
            ->assertOk()
            ->assertJsonPath('data.assignmentId', $activeAssignment->id)
            ->assertJsonPath('data.status', JobAssignment::STATUS_ASSIGNED)
            ->assertJsonCount(3, 'assignments');
        $driverAssignments->assertJsonFragment(['id' => 'VMC-QUEUE-ACTIVE']);
        $driverAssignments->assertJsonFragment(['id' => 'VMC-QUEUE-NEXT-01']);
        $driverAssignments->assertJsonFragment(['id' => 'VMC-QUEUE-NEXT-02']);
        $driverAssignments->assertJsonMissing(['id' => 'VMC-QUEUE-TEAM-ONLY']);
        $this->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonFragment(['status' => JobAssignment::STATUS_QUEUED]);

        $this->actingAs($operator, 'sanctum')
            ->patchJson('/api/v1/jobs/'.$activeJob->id.'/assignments/'.$activeAssignment->id.'/status', [
                'status' => JobAssignment::STATUS_WORK_DONE,
            ])
            ->assertOk();

        $this->assertSame(JobAssignment::STATUS_ASSIGNED, JobAssignment::query()->findOrFail($firstQueuedId)->status);
        $this->assertSame(JobAssignment::STATUS_QUEUED, JobAssignment::query()->findOrFail($secondQueuedId)->status);
        $this->assertSame(Vehicle::STATUS_DEPLOYED, $vehicle->fresh()->status);
        $this->assertSame(Team::STATUS_DEPLOYED, $team->fresh()->status);
        $this->assertSame(1, $driver->notifications()->where('type', DispatchAssigned::class)->count());
        $this->assertDatabaseHas('job_status_history', [
            'job_assignment_id' => $firstQueuedId,
            'previous_status' => JobAssignment::STATUS_QUEUED,
            'new_status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $this->patchJson('/api/v1/jobs/'.$activeJob->id.'/assignments/'.$activeAssignment->id.'/status', [
            'status' => JobAssignment::STATUS_ASSIGNED,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$firstQueuedId.'/work-done', [])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_WORK_DONE);
        $this->assertSame(JobAssignment::STATUS_ASSIGNED, JobAssignment::query()->findOrFail($secondQueuedId)->status);
        $this->assertSame(JobAssignment::STATUS_QUEUED, JobAssignment::query()->findOrFail($teamQueuedId)->status);

        $this->postJson('/api/v1/driver/assignments/'.$secondQueuedId.'/work-done', [])
            ->assertOk()
            ->assertJsonPath('data.status', JobAssignment::STATUS_WORK_DONE);
        $this->assertSame(JobAssignment::STATUS_ASSIGNED, JobAssignment::query()->findOrFail($teamQueuedId)->status);
    }

    public function test_queued_assignment_waits_for_an_off_road_vehicle_to_return_on_road(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $vehicle = $this->vehicle($station, 'VMC-QUEUE-OFF-ROAD');
        $vehicle->update([
            'status' => Vehicle::STATUS_DEPLOYED,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
        ]);
        $activeJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-OFF-ROAD-ACTIVE');
        $activeAssignment = JobAssignment::query()->create([
            'emergency_job_id' => $activeJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_by' => $operator->id,
            'assigned_at' => now()->subMinute(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $queuedJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-OFF-ROAD-NEXT');

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/jobs/'.$queuedJob->id.'/assignments', [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_user_id' => $driver->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_QUEUED);
        $queuedAssignment = JobAssignment::query()->where('emergency_job_id', $queuedJob->id)->firstOrFail();

        $vehicle->update(['operational_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD]);
        $this->patchJson('/api/v1/jobs/'.$activeJob->id.'/assignments/'.$activeAssignment->id.'/status', [
            'status' => JobAssignment::STATUS_WORK_DONE,
        ])->assertOk();
        $this->assertSame(JobAssignment::STATUS_QUEUED, $queuedAssignment->fresh()->status);

        $this->actingAs($driver, 'sanctum')
            ->patchJson('/api/v1/vehicles/'.$vehicle->id.'/operational-status', [
                'status' => Vehicle::OPERATIONAL_STATUS_ON_ROAD,
            ])
            ->assertOk();
        $this->assertSame(JobAssignment::STATUS_ASSIGNED, $queuedAssignment->fresh()->status);
    }

    public function test_off_road_busy_vehicle_can_be_queued_with_its_current_driver(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $vehicle = $this->vehicle($station, 'VMC-QUEUE-OFF-ROAD-PAIR');
        $vehicle->update([
            'status' => Vehicle::STATUS_DEPLOYED,
            'operational_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD,
        ]);
        $activeJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-OFF-ROAD-PAIR-ACTIVE');
        JobAssignment::query()->create([
            'emergency_job_id' => $activeJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_driver_user_id' => $driver->id,
            'assigned_by' => $operator->id,
            'assigned_at' => now()->subMinute(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $queuedJob = $this->assignedJob($operator, $ward, 'VMC-QUEUE-OFF-ROAD-PAIR-NEXT');

        $this->actingAs($operator, 'sanctum')
            ->get(route('app.jobs.show', $queuedJob))
            ->assertOk()
            ->assertSee($vehicle->vehicle_number);

        $this->postJson('/api/v1/jobs/'.$queuedJob->id.'/assignments', [
            'assignments' => [[
                'fire_station_id' => $station->id,
                'vehicle_id' => $vehicle->id,
                'driver_user_id' => $driver->id,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_QUEUED);
    }

    public function test_dispatcher_cannot_assign_an_explicitly_off_road_vehicle(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $vehicle = $this->vehicle($station, 'VMC-OFF-ROAD-01');
        $vehicle->update(['operational_status' => Vehicle::OPERATIONAL_STATUS_OFF_ROAD]);
        $driver = $this->driverAt($station);
        $job = $this->assignedJob($operator, $ward, 'VMC-OFF-ROAD-JOB');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/jobs/{$job->id}/assignments", [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_user_id' => $driver->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $this->assertDatabaseCount('job_assignments', 0);
    }

    public function test_dispatch_requires_a_unique_available_driver_for_each_vehicle(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $vehicleOne = $this->vehicle($station, 'VMC-DRIVER-REQUIRED-01');
        $vehicleTwo = $this->vehicle($station, 'VMC-DRIVER-REQUIRED-02');
        $driver = $this->driverAt($station);
        $job = $this->assignedJob($operator, $ward, 'VMC-DRIVER-REQUIRED');

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/jobs/{$job->id}/assignments", [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicleOne->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments.0.driver_user_id');

        $this->postJson("/api/v1/jobs/{$job->id}/assignments", [
            'assignments' => [
                [
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicleOne->id,
                    'driver_user_id' => $driver->id,
                ],
                [
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicleTwo->id,
                    'driver_user_id' => $driver->id,
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignments');

        $this->assertDatabaseCount('job_assignments', 0);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicleOne->fresh()->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicleTwo->fresh()->status);
    }

    public function test_vehicle_driver_can_only_update_assignments_explicitly_assigned_to_them(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station->users()->attach($driver->id);

        $activeTeam = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Driver Active Team',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $activeTeam->members()->attach($driver->id, ['joined_at' => now()->toDateString()]);

        $inactiveTeam = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Driver Inactive Team',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $inactiveTeam->members()->attach($driver->id, [
            'joined_at' => now()->subMonth()->toDateString(),
            'left_at' => now()->subDay()->toDateString(),
        ]);

        $activeJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-ACTIVE');
        $inactiveJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-INACTIVE');
        $outsideJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-OUTSIDE');

        $otherDriver = $this->driverAt($station);
        $activeAssignment = $this->assignment($station, $activeJob, 'DRIVER-ACTIVE-01', $activeTeam, $driver);
        $inactiveAssignment = $this->assignment($station, $inactiveJob, 'DRIVER-INACTIVE-01', $inactiveTeam, $otherDriver);
        $outsideAssignment = $this->assignment($station, $outsideJob, 'DRIVER-OUTSIDE-01');

        $this->actingAs($driver, 'sanctum')
            ->patchJson("/api/v1/jobs/{$activeJob->id}/assignments/{$activeAssignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_EN_ROUTE);

        foreach ([
            [$inactiveJob, $inactiveAssignment],
            [$outsideJob, $outsideAssignment],
        ] as [$job, $assignment]) {
            $this->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
            ])
                ->assertNotFound();
        }

        $this->assertSame(JobAssignment::STATUS_ASSIGNED, $inactiveAssignment->fresh()->status);
        $this->assertSame(JobAssignment::STATUS_ASSIGNED, $outsideAssignment->fresh()->status);
    }

    public function test_vehicle_driver_can_update_assignment_status_with_post_for_iis_compatibility(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $job = $this->assignedJob($operator, $ward, 'VMC-IIS-POST-STATUS');
        $assignment = $this->assignment($station, $job, 'IIS-POST-01', null, $driver);

        $this->actingAs($driver, 'sanctum')
            ->postJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
                'remarks' => 'Status updated through the IIS-compatible POST route.',
            ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_EN_ROUTE);
    }

    public function test_vehicle_driver_can_change_assignment_status_from_the_website(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'leader_user_id' => $driver->id,
            'name' => 'Website Driver Team',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $station->users()->attach($driver->id);
        $job = $this->assignedJob($operator, $ward, 'VMC-WEB-DRIVER-STATUS');
        $assignment = $this->assignment($station, $job, 'WEB-DRIVER-01', $team, $driver);

        $this->actingAs($driver)
            ->get(route('app.jobs.show', $job))
            ->assertOk()
            ->assertSee('<select name="status">', false)
            ->assertSee('<option value="'.JobAssignment::STATUS_COMPLETED.'"', false)
            ->assertSee('Status update note');

        $this->patch(route('app.jobs.assignments.status', [$job->id, $assignment->id]), [
            'status' => JobAssignment::STATUS_EN_ROUTE,
            'remarks' => 'Driver is on the way.',
        ])
            ->assertRedirect(route('app.jobs.show', $job))
            ->assertSessionHas('status', 'Unit status updated.');

        $this->assertSame(JobAssignment::STATUS_EN_ROUTE, $assignment->fresh()->status);
    }

    public function test_incident_detail_displays_assignment_controls_and_unit_history(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $this->vehicle($station, 'VMC-FIRE-03');
        $this->actingAs($operator);
        $job = EmergencyJob::query()->create([
            ...$this->incidentData($ward),
            'job_id' => 'VMC-ASSIGNMENT-DETAIL',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $operator->id,
        ]);

        $this->get(route('app.jobs.show', $job))
            ->assertOk()
            ->assertSee('Assign one or more units')
            ->assertSee('Vehicle driver')
            ->assertSee($driver->name)
            ->assertSee('Response units')
            ->assertSee('Add another vehicle');
    }

    public function test_incident_detail_shows_help_escalation_and_control_room_replies(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = $this->driverAt($station);
        $job = $this->assignedJob($operator, $ward, 'VMC-DRIVER-HELP-REMARK');
        $assignment = $this->assignment($station, $job, 'VMC-HELP-REMARK-01', null, $driver);

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/escalate', [
                'reason' => 'Additional equipment required.',
            ])
            ->assertOk();

        $this->actingAs($driver, 'sanctum')
            ->postJson('/api/v1/driver/assignments/'.$assignment->id.'/need-help', [
                'helpType' => 'AMBULANCE',
                'comments' => 'A patient needs urgent medical transport.',
            ])
            ->assertOk();

        $this->actingAs($operator, 'sanctum')
            ->patchJson('/api/v1/jobs/'.$job->id.'/assignments/'.$assignment->id.'/status', [
                'status' => JobAssignment::STATUS_EN_ROUTE,
                'remarks' => 'Control room dispatched an ambulance.',
            ])
            ->assertOk();

        $statusBeforeReply = $assignment->fresh()->status;
        $this->actingAs($operator)
            ->post(route('app.jobs.reply', $job), [
                'assignment_id' => $assignment->id,
                'reply' => 'Ambulance has been dispatched to your location.',
            ])
            ->assertRedirect(route('app.jobs.show', $job).'#incident-conversation');

        $this->assertSame($statusBeforeReply, $assignment->fresh()->status);
        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job->id,
            'job_assignment_id' => $assignment->id,
            'changed_by' => $operator->id,
            'remarks' => 'Ambulance has been dispatched to your location.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $driver->id,
            'type' => DriverRemarkReplyAlert::class,
        ]);

        $remark = JobRemark::query()->where('emergency_job_id', $job->id)->firstOrFail();
        $this->actingAs($operator)
            ->get(route('app.jobs.show', $job))
            ->assertOk()
            ->assertSee('Incident conversation')
            ->assertSee('name="reply"', false)
            ->assertSee('Send reply')
            ->assertSee('Escalation')
            ->assertSee('Additional equipment required.')
            ->assertSee('Help request')
            ->assertSee('Control room reply')
            ->assertSee('Control room dispatched an ambulance.')
            ->assertSee('Ambulance has been dispatched to your location.')
            ->assertSee($operator->name)
            ->assertSee($driver->name)
            ->assertDontSee('Driver action [need_help]')
            ->assertSee('A patient needs urgent medical transport.')
            ->assertSee($remark->created_at->format('M j, Y g:i A'));
    }

    /**
     * @return array{User, FireStation, Ward}
     */
    private function operatorWithStation(): array
    {
        $operator = User::factory()->create();
        $operator->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-ASSIGN',
            'name' => 'Assignment Station',
            'address' => '1 Response Road',
        ]);
        $station->users()->attach($operator->id);
        $ward = Ward::query()->create(['code' => 'WARD-ASSIGN', 'name' => 'Assignment Ward']);
        $station->wards()->attach($ward->id);

        return [$operator, $station, $ward];
    }

    private function vehicle(FireStation $station, string $number): Vehicle
    {
        return Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => $number,
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
    }

    private function assignedJob(User $operator, Ward $ward, string $jobId): EmergencyJob
    {
        return EmergencyJob::query()->create([
            ...$this->incidentData($ward),
            'job_id' => $jobId,
            'status' => EmergencyJob::STATUS_ASSIGNED,
            'created_by' => $operator->id,
        ]);
    }

    private function assignment(
        FireStation $station,
        EmergencyJob $job,
        string $vehicleNumber,
        ?Team $team = null,
        ?User $driver = null,
    ): JobAssignment {
        $vehicle = $this->vehicle($station, $vehicleNumber);
        $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);

        return JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'team_id' => $team?->id,
            'team_leader_user_id' => $team?->leader_user_id,
            'assigned_driver_user_id' => $driver?->id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
    }

    private function incidentData(Ward $ward): array
    {
        return [
            'caller_name' => 'Assignment Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'ward_id' => $ward->id,
            'society_name' => 'Assignment Society',
            'incident_address' => '10 Test Road, Vadodara',
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'remarks' => 'Smoke reported at the premises.',
        ];
    }
}
