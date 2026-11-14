<?php

namespace Tests\Feature;

use App\Http\Controllers\VehicleDetailsController;
use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use App\Services\VehicleDisplayStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VehicleDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Route::get('/test/vehicles/{vehicle}', [VehicleDetailsController::class, 'show'])
            ->middleware('auth')
            ->name('test.vehicle-details');
    }

    public function test_dashboard_vehicle_rows_show_a_link_to_the_open_ticket_list(): void
    {
        [$admin, $station, $ward, $vehicle] = $this->vehicleSetup();
        $this->jobWithAssignment($station, $ward, $vehicle, 'OPEN-TICKET-01', EmergencyJob::STATUS_EN_ROUTE);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Open tickets')
            ->assertSee('/vehicles/'.$vehicle->id.'#open-assignments', false)
            ->assertSee('View 1 open tickets for '.$vehicle->vehicle_number);
    }

    public function test_vehicle_details_route_opens_from_the_dashboard_vehicle_url(): void
    {
        [$admin, , , $vehicle] = $this->vehicleSetup();

        $this->actingAs($admin)
            ->get('/vehicles/'.$vehicle->id)
            ->assertOk()
            ->assertSee($vehicle->vehicle_number);
    }

    public function test_details_page_shows_gps_freshness_assignments_and_closed_tickets_latest_first(): void
    {
        [$admin, $station, $ward, $vehicle] = $this->vehicleSetup();
        $activeJob = $this->jobWithAssignment(
            $station,
            $ward,
            $vehicle,
            'ACTIVE-TICKET-01',
            EmergencyJob::STATUS_EN_ROUTE,
        );
        $activeAssignment = $activeJob->assignments()->sole();
        JobStatusHistory::query()->create([
            'emergency_job_id' => $activeJob->id,
            'job_assignment_id' => $activeAssignment->id,
            'previous_status' => EmergencyJob::STATUS_ASSIGNED,
            'new_status' => EmergencyJob::STATUS_EN_ROUTE,
            'changed_at' => now()->subMinutes(4),
        ]);
        $this->closedJob($station, $ward, $vehicle, 'CLOSED-OLDER', now()->subDay());
        $this->closedJob($station, $ward, $vehicle, 'CLOSED-LATEST', now()->subHour());
        VehicleGpsLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $activeAssignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'recorded_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($admin)
            ->get('/test/vehicles/'.$vehicle->id)
            ->assertOk()
            ->assertSee($vehicle->vehicle_number)
            ->assertSee('On Road / Moving')
            ->assertSee('Fresh')
            ->assertSee('22.30720, 73.18120')
            ->assertSee('ACTIVE-TICKET-01')
            ->assertSee('Recently closed tickets')
            ->assertSeeInOrder(['CLOSED-LATEST', 'CLOSED-OLDER'])
            ->assertSee('View Details')
            ->assertSee('Assignment / incident status history');
    }

    public function test_details_page_scopes_vehicle_access_and_status_falls_back_without_schema_flag(): void
    {
        [, $station, $ward, $vehicle] = $this->vehicleSetup();
        $otherStation = FireStation::query()->create([
            'code' => 'FS-DETAIL-OUTSIDE',
            'name' => 'Outside detail station',
            'address' => 'Outside',
        ]);
        $outsideWard = Ward::query()->create([
            'code' => 'WARD-DETAIL-OUTSIDE',
            'name' => 'Outside detail ward',
        ]);
        $otherStation->wards()->attach($outsideWard->id);
        $outsideVehicle = Vehicle::query()->create([
            'fire_station_id' => $otherStation->id,
            'vehicle_number' => 'DETAIL-OUTSIDE-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station->users()->attach($stationAdmin->id);

        $this->actingAs($stationAdmin)
            ->get('/test/vehicles/'.$vehicle->id)
            ->assertOk();
        $this->get('/test/vehicles/'.$outsideVehicle->id)->assertNotFound();

        $vehicle->setAttribute('operational_status', 'ON_ROAD');
        $this->assertSame('ON_ROAD', VehicleDisplayStatus::describe($vehicle)['code']);
    }

    /**
     * @return array{User, FireStation, Ward, Vehicle}
     */
    private function vehicleSetup(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-VEHICLE-DETAIL',
            'name' => 'Vehicle detail station',
            'address' => '1 Vehicle Road',
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-VEHICLE-DETAIL', 'name' => 'Detail Ward']);
        $station->wards()->attach($ward->id);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'DETAIL-VEHICLE-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        return [$admin, $station, $ward, $vehicle];
    }

    private function jobWithAssignment(
        FireStation $station,
        Ward $ward,
        Vehicle $vehicle,
        string $jobId,
        string $status,
        ?Carbon $releasedAt = null,
    ): EmergencyJob {
        $job = EmergencyJob::query()->create([
            'job_id' => $jobId,
            'caller_name' => 'Caller '.$jobId,
            'caller_phone' => '90000-00001',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subHours(3),
            'ward_id' => $ward->id,
            'incident_address' => 'Recorded address',
            'status' => $status,
        ]);
        $job->assignments()->create([
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now()->subHours(2),
            'released_at' => $releasedAt,
            'status' => $status === EmergencyJob::STATUS_EN_ROUTE
                ? JobAssignment::STATUS_EN_ROUTE
                : JobAssignment::STATUS_COMPLETED,
        ]);

        return $job;
    }

    private function closedJob(
        FireStation $station,
        Ward $ward,
        Vehicle $vehicle,
        string $jobId,
        Carbon $closedAt,
    ): void {
        $job = $this->jobWithAssignment(
            $station,
            $ward,
            $vehicle,
            $jobId,
            EmergencyJob::STATUS_COMPLETED,
            $closedAt,
        );
        $assignment = $job->assignments()->sole();
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'job_assignment_id' => $assignment->id,
            'previous_status' => EmergencyJob::STATUS_IN_PROGRESS,
            'new_status' => EmergencyJob::STATUS_COMPLETED,
            'changed_at' => $closedAt,
        ]);
    }
}
