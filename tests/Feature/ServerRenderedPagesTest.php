<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerRenderedPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_website_pages_require_login(): void
    {
        FireStation::query()->create([
            'code' => 'PRIVATE-01',
            'name' => 'Protected Station',
            'address' => 'Private address',
        ]);

        foreach (['/', '/dashboard', '/stations', '/wards', '/roles', '/vehicles', '/teams', '/jobs'] as $path) {
            $this->get($path)
                ->assertRedirect(route('login'))
                ->assertDontSee('Protected Station')
                ->assertDontSee('PRIVATE-01');
        }

        $this->get('/login')->assertOk()->assertSee('Sign in to your account');
        $this->get('/login')
            ->assertOk()
            ->assertSee('images/vadodara-municipal-corporation-logo.jpeg')
            ->assertSee('Vadodara Municipal Corporation');
    }

    public function test_server_rendered_dashboard_observes_station_scope(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdminRole = Role::query()->where('code', 'FIRE_STATION_ADMIN')->firstOrFail();
        $stationAdmin->roles()->attach($stationAdminRole);
        $stationAdminRole->permissions()->syncWithoutDetaching([
            Permission::query()->where('code', 'jobs.create')->firstOrFail()->id,
        ]);

        $assignedStation = FireStation::query()->create([
            'code' => 'FS-ASSIGNED',
            'name' => 'Assigned Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $otherStation = FireStation::query()->create([
            'code' => 'FS-PRIVATE',
            'name' => 'Private Station',
            'address' => '2 North Road',
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-01', 'name' => 'Assigned Ward']);
        $assignedStation->wards()->attach($ward->id);
        $otherStation->users()->attach(User::factory()->create()->id);

        $this->actingAs($stationAdmin)
            ->get('/')
            ->assertOk()
            ->assertSee('class="operations-dashboard-page operations-dashboard-layout"', false);

        $this->actingAs($stationAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Incident Dashboard')
            ->assertSee('<span class="current-page-title" aria-current="page">Incident Dashboard</span>', false)
            ->assertSee('class="topbar-action" href="'.route('app.jobs.new').'"', false)
            ->assertSee('images/vadodara-municipal-corporation-logo.jpeg')
            ->assertSee('Recent incidents')
            ->assertSee('Vehicles')
            ->assertDontSee('Incoming calls')
            ->assertDontSee('Incident & station map')
            ->assertDontSee('Fire station availability')
            ->assertDontSee('Ward-wise pending jobs');
    }

    public function test_dashboard_recent_incidents_filters_match_lifecycle_statuses(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-FILTERS',
            'name' => 'Filter Test Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-FILTERS', 'name' => 'Filter Test Ward']);
        $station->wards()->attach($ward->id);

        $jobsByStatus = [];
        foreach ([
            EmergencyJob::STATUS_RECEIVED => 'FILTER-RECEIVED',
            EmergencyJob::STATUS_ASSIGNED => 'FILTER-ASSIGNED',
            EmergencyJob::STATUS_EN_ROUTE => 'FILTER-EN-ROUTE',
            EmergencyJob::STATUS_ARRIVED => 'FILTER-ARRIVED',
            EmergencyJob::STATUS_IN_PROGRESS => 'FILTER-IN-PROGRESS',
            EmergencyJob::STATUS_COMPLETED => 'FILTER-COMPLETED',
            EmergencyJob::STATUS_DUPLICATE => 'FILTER-DUPLICATE',
            EmergencyJob::STATUS_TRANSFERRED_TO_112 => 'FILTER-TRANSFERRED',
        ] as $status => $jobId) {
            $jobsByStatus[$status] = EmergencyJob::query()->create([
                'job_id' => $jobId,
                'caller_name' => 'Filter Test Caller',
                'caller_phone' => '+91-90000-01010',
                'incident_type' => 'STRUCTURE_FIRE',
                'priority' => 'HIGH',
                'occurred_at' => now(),
                'ward_id' => $ward->id,
                'incident_address' => 'Filter Test Location',
                'latitude' => 22.3072,
                'longitude' => 73.1812,
                'status' => $status,
            ]);
        }

        $onRoadVehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FILTER-UNIT-ROAD',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_DEPLOYED,
        ]);
        $onSiteVehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FILTER-UNIT-SITE',
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_DEPLOYED,
        ]);
        $assignedVehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FILTER-UNIT-ASSIGNED',
            'vehicle_type' => 'TANKER',
            'status' => Vehicle::STATUS_DEPLOYED,
        ]);
        Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FILTER-UNIT-OFF',
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_MAINTENANCE,
        ]);
        Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FILTER-UNIT-IDLE',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        foreach ([
            [$onRoadVehicle, $jobsByStatus[EmergencyJob::STATUS_EN_ROUTE], JobAssignment::STATUS_EN_ROUTE],
            [$onSiteVehicle, $jobsByStatus[EmergencyJob::STATUS_ARRIVED], JobAssignment::STATUS_ARRIVED],
            [$assignedVehicle, $jobsByStatus[EmergencyJob::STATUS_ASSIGNED], JobAssignment::STATUS_ASSIGNED],
        ] as [$vehicle, $job, $status]) {
            JobAssignment::query()->create([
                'emergency_job_id' => $job->id,
                'fire_station_id' => $station->id,
                'vehicle_id' => $vehicle->id,
                'assigned_at' => now(),
                'status' => $status,
            ]);
        }
        foreach ([$onRoadVehicle, $onSiteVehicle, $assignedVehicle] as $vehicle) {
            VehicleGpsLog::query()->create([
                'vehicle_id' => $vehicle->id,
                'latitude' => 22.3072,
                'longitude' => 73.1812,
                'speed_kph' => $vehicle->is($onRoadVehicle) ? 32.5 : null,
                'recorded_at' => now(),
            ]);
        }

        $this->actingAs($stationAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Recent incidents')
            ->assertSee('Incident details')
            ->assertSee('Caller name / phone')
            ->assertSee('Elapsed time')
            ->assertSee('ops-incidents-toolbar', false)
            ->assertSee('ops-incidents-title-main', false)
            ->assertSee('ops-incident-search', false)
            ->assertSee('id="incident-search"', false)
            ->assertSeeInOrder(['ops-incident-map-link', 'Filter Test Location'], false)
            ->assertDontSee('ops-dashboard-action', false)
            ->assertSee('ops-incident-map-link', false)
            ->assertSee('ops-live-map', false)
            ->assertSee('data-map-incident="FILTER-RECEIVED"', false)
            ->assertSee('data-map-ward-filter', false)
            ->assertSee('data-map-search-vehicle', false)
            ->assertSee('data-map-search-incident', false)
            ->assertSee('data-map-satellite disabled', false)
            ->assertSee('data-map-boundaries disabled', false)
            ->assertSee('Ward boundary data is not available')
            ->assertSee('ops-vehicle-tabs', false)
            ->assertSee('ops-vehicle-header-tools', false)
            ->assertSee('aria-label="Vehicle pagination"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Map View')
            ->assertSee('data-incident-view="table"', false)
            ->assertSee('data-incident-view="map"', false)
            ->assertSee('data-incident-view-panel="map" hidden', false)
            ->assertSee('Vehicles')
            ->assertDontSee('data-activity-carousel', false)
            ->assertSee('Total incidents</span><strong>8', false)
            ->assertSee('Total vehicles</span><strong>5', false)
            ->assertSee('On Road</span><strong>1', false)
            ->assertSee('Off Road</span><strong>1', false)
            ->assertSee('On Site</span><strong>1', false)
            ->assertSee('Idle / Free</span><strong>1', false)
            ->assertSee('Transfer to 112</span><strong>1', false)
            ->assertSee('data-incident-row="FILTER-RECEIVED"', false)
            ->assertSee('data-incident-row="FILTER-EN-ROUTE"', false)
            ->assertDontSee('data-incident-row="FILTER-COMPLETED"', false)
            ->assertSee('Open <span>5</span>', false)
            ->assertSee('Closed <span>3</span>', false)
            ->assertSee('On Road <span>1</span>', false)
            ->assertSee('Off Road <span>1</span>', false)
            ->assertSee('Assigned <span>1</span>', false)
            ->assertSee('At Incident <span>1</span>', false);

        $this->get('/dashboard?status_filter=pending')
            ->assertOk()
            ->assertSee('data-incident-row="FILTER-RECEIVED"', false)
            ->assertSee('data-incident-row="FILTER-ASSIGNED"', false)
            ->assertDontSee('data-incident-row="FILTER-EN-ROUTE"', false);

        $this->get('/dashboard?status_filter=on_site')
            ->assertOk()
            ->assertSee('data-incident-row="FILTER-ARRIVED"', false)
            ->assertSee('data-incident-row="FILTER-IN-PROGRESS"', false)
            ->assertDontSee('data-incident-row="FILTER-ASSIGNED"', false);

        $this->get('/dashboard?status_filter=en_route')
            ->assertOk()
            ->assertSee('data-incident-row="FILTER-EN-ROUTE"', false)
            ->assertDontSee('data-incident-row="FILTER-ARRIVED"', false);

        $this->get('/dashboard?status_filter=closed')
            ->assertOk()
            ->assertSee('data-incident-row="FILTER-COMPLETED"', false)
            ->assertSee('data-incident-row="FILTER-DUPLICATE"', false)
            ->assertSee('data-incident-row="FILTER-TRANSFERRED"', false)
            ->assertDontSee('data-incident-row="FILTER-RECEIVED"', false);

        $this->get('/dashboard?vehicle_filter=on_road')
            ->assertOk()
            ->assertSee('data-vehicle-row="FILTER-UNIT-ROAD"', false)
            ->assertDontSee('data-vehicle-row="FILTER-UNIT-SITE"', false);

        $this->get(route('dashboard.map-data'))
            ->assertOk()
            ->assertJsonFragment([
                'job_id' => 'FILTER-RECEIVED',
                'priority' => 'HIGH',
                'latitude' => 22.3072,
            ])
            ->assertJsonFragment([
                'number' => 'FILTER-UNIT-ROAD',
                'status_code' => 'ON_ROAD',
                'speed_kph' => 32.5,
            ]);

        $this->get(route('dashboard.map-data', ['status_filter' => 'pending']))
            ->assertOk()
            ->assertJsonCount(2, 'data.incidents')
            ->assertJsonMissing(['job_id' => 'FILTER-EN-ROUTE']);
    }

    public function test_dashboard_tables_support_independent_search_and_pagination(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-PAGINATION',
            'name' => 'Pagination Test Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-PAGINATION', 'name' => 'Pagination Test Ward']);
        $station->wards()->attach($ward->id);

        for ($index = 1; $index <= 10; $index++) {
            EmergencyJob::query()->create([
                'job_id' => sprintf('PAGE-JOB-%02d', $index),
                'caller_name' => 'Pagination Caller',
                'caller_phone' => '+91-90000-01010',
                'incident_type' => 'STRUCTURE_FIRE',
                'priority' => 'NORMAL',
                'occurred_at' => now()->addSeconds($index),
                'ward_id' => $ward->id,
                'incident_address' => 'Pagination Test Location',
                'status' => EmergencyJob::STATUS_RECEIVED,
            ]);
            Vehicle::query()->create([
                'fire_station_id' => $station->id,
                'vehicle_number' => sprintf('PAGE-UNIT-%02d', $index),
                'vehicle_type' => 'FIRE_ENGINE',
                'status' => Vehicle::STATUS_AVAILABLE,
            ]);
        }

        $this->actingAs($stationAdmin)
            ->get('/dashboard?status_filter=open&incident_search=PAGE-JOB-10&vehicle_filter=idle&vehicle_search=PAGE-UNIT-10')
            ->assertOk()
            ->assertSee('data-incident-row="PAGE-JOB-10"', false)
            ->assertDontSee('data-incident-row="PAGE-JOB-01"', false)
            ->assertSee('PAGE-UNIT-10')
            ->assertDontSee('PAGE-UNIT-01');

        $this->get('/dashboard?status_filter=open&incidents_page=2')
            ->assertOk()
            ->assertSee('data-incident-row="PAGE-JOB-02"', false)
            ->assertDontSee('data-incident-row="PAGE-JOB-10"', false)
            ->assertSee('Showing 9–10 of 10 incidents', false);

        $this->get('/dashboard?vehicle_filter=idle&vehicles_page=2')
            ->assertOk()
            ->assertSee('PAGE-UNIT-09')
            ->assertSee('PAGE-UNIT-10')
            ->assertSee('PAGE-UNIT-06')
            ->assertDontSee('PAGE-UNIT-01')
            ->assertSee('Showing 6–10 of 10 vehicles', false)
            ->assertSee('ops-vehicle-pagination', false);
    }

    public function test_directory_pages_are_server_rendered_searchable_and_station_scoped(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-CENTRAL',
            'name' => 'Central Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $other = FireStation::query()->create([
            'code' => 'FS-NORTH',
            'name' => 'North Station',
            'address' => '2 North Road',
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-01', 'name' => 'Central Ward']);
        $station->wards()->attach($ward->id);
        $other->wards()->attach(Ward::query()->create(['code' => 'WARD-02', 'name' => 'North Ward'])->id);

        $this->actingAs($stationAdmin)
            ->get('/stations?search=CENTRAL')
            ->assertOk()
            ->assertSee('operations-dashboard-page')
            ->assertSee('/css/operations-theme.css')
            ->assertSee('/css/dashboard-polish.css')
            ->assertSee('class="sidebar"', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('Central Station')
            ->assertDontSee('North Station');

        $this->get('/wards')
            ->assertOk()
            ->assertSee('Central Ward')
            ->assertDontSee('North Ward');

        $this->get('/roles')
            ->assertOk()
            ->assertSee('FIRE_STATION_ADMIN')
            ->assertSee('Permission');
    }

    public function test_master_data_and_incident_views_are_station_scoped(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $assignedStation = FireStation::query()->create([
            'code' => 'FS-OPS-CENTRAL',
            'name' => 'Operations Central',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $otherStation = FireStation::query()->create([
            'code' => 'FS-OPS-NORTH',
            'name' => 'Operations North',
            'address' => '2 North Road',
        ]);
        $assignedWard = Ward::query()->create(['code' => 'WARD-OPS-01', 'name' => 'Operations Central Ward']);
        $otherWard = Ward::query()->create(['code' => 'WARD-OPS-02', 'name' => 'Operations North Ward']);
        $assignedStation->wards()->attach($assignedWard);
        $otherStation->wards()->attach($otherWard);
        Vehicle::query()->create([
            'fire_station_id' => $assignedStation->id,
            'vehicle_number' => 'OPS-CENTRAL-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        Vehicle::query()->create([
            'fire_station_id' => $otherStation->id,
            'vehicle_number' => 'OPS-NORTH-01',
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        Team::query()->create([
            'fire_station_id' => $assignedStation->id,
            'name' => 'Operations Central Team',
        ]);
        Team::query()->create([
            'fire_station_id' => $otherStation->id,
            'name' => 'Operations North Team',
        ]);
        EmergencyJob::query()->create([
            'job_id' => 'OPS-JOB-CENTRAL',
            'caller_name' => 'Central Caller',
            'caller_phone' => '+91-90000-01001',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $assignedWard->id,
            'incident_address' => 'Central incident location',
            'status' => EmergencyJob::STATUS_RECEIVED,
        ]);
        EmergencyJob::query()->create([
            'job_id' => 'OPS-JOB-NORTH',
            'caller_name' => 'North Caller',
            'caller_phone' => '+91-90000-01002',
            'incident_type' => 'ROAD_ACCIDENT',
            'priority' => 'NORMAL',
            'occurred_at' => now(),
            'ward_id' => $otherWard->id,
            'incident_address' => 'North incident location',
            'status' => EmergencyJob::STATUS_RECEIVED,
        ]);

        $this->actingAs($stationAdmin)
            ->get('/vehicles')
            ->assertOk()
            ->assertSee('OPS-CENTRAL-01')
            ->assertDontSee('OPS-NORTH-01');

        $this->get('/teams')
            ->assertOk()
            ->assertSee('Operations Central Team')
            ->assertDontSee('Operations North Team');

        $this->get('/jobs')
            ->assertOk()
            ->assertSee('OPS-JOB-CENTRAL')
            ->assertDontSee('OPS-JOB-NORTH');

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Recent incidents')
            ->assertSee('Vehicles')
            ->assertSee('OPS-JOB-CENTRAL')
            ->assertSee('OPS-CENTRAL-01')
            ->assertDontSee('OPS-NORTH-01')
            ->assertDontSee('Incident & station map')
            ->assertDontSee('OPS-JOB-NORTH');
    }

    public function test_phase_four_status_queues_show_the_matching_station_scoped_incidents(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-QUEUE',
            'name' => 'Queue Test Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-QUEUE', 'name' => 'Queue Test Ward']);
        $station->wards()->attach($ward->id);

        foreach ([
            EmergencyJob::STATUS_RECEIVED => 'QUEUE-RECEIVED',
            EmergencyJob::STATUS_ASSIGNED => 'QUEUE-ASSIGNED',
            EmergencyJob::STATUS_EN_ROUTE => 'QUEUE-EN-ROUTE',
            EmergencyJob::STATUS_ARRIVED => 'QUEUE-ARRIVED',
            EmergencyJob::STATUS_IN_PROGRESS => 'QUEUE-IN-PROGRESS',
            EmergencyJob::STATUS_COMPLETED => 'QUEUE-COMPLETED',
        ] as $status => $jobId) {
            EmergencyJob::query()->create([
                'job_id' => $jobId,
                'caller_name' => 'Queue Test Caller',
                'caller_phone' => '+91-90000-01010',
                'incident_type' => 'STRUCTURE_FIRE',
                'priority' => 'HIGH',
                'occurred_at' => now(),
                'ward_id' => $ward->id,
                'incident_address' => 'Queue Test Location',
                'status' => $status,
            ]);
        }

        $this->actingAs($stationAdmin)
            ->get('/jobs/active')
            ->assertOk()
            ->assertSee('QUEUE-ASSIGNED')
            ->assertSee('QUEUE-EN-ROUTE')
            ->assertSee('QUEUE-ARRIVED')
            ->assertSee('QUEUE-IN-PROGRESS')
            ->assertDontSee('QUEUE-RECEIVED')
            ->assertDontSee('QUEUE-COMPLETED');

        $this->get('/jobs/en-route')
            ->assertOk()
            ->assertSee('QUEUE-EN-ROUTE')
            ->assertDontSee('QUEUE-ASSIGNED');

        $this->get('/jobs/completed')
            ->assertOk()
            ->assertSee('QUEUE-COMPLETED')
            ->assertDontSee('QUEUE-IN-PROGRESS');
    }

    public function test_pages_require_their_configured_permissions_for_authenticated_users(): void
    {
        $teamMember = User::factory()->create();
        $teamMember->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));

        $this->actingAs($teamMember)
            ->get('/dashboard')
            ->assertForbidden();

        $this->get('/stations')->assertForbidden();
    }

    public function test_server_login_and_logout_use_web_session(): void
    {
        $user = User::factory()->create(['password' => 'correct horse battery']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('/css/operations-theme.css')
            ->assertSee('Sign in to your account');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct horse battery',
        ])->assertRedirect('/dashboard')
            ->assertSessionHas('_token');

        $this->get('/dashboard')->assertOk()->assertSee($user->name);

        $this->post('/logout')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
