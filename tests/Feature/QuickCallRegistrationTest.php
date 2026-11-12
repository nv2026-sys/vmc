<?php

namespace Tests\Feature;

use App\Http\Controllers\QuickCallController;
use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class QuickCallRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        if (! Route::has('app.jobs.quick-call')
            || ! Route::has('app.jobs.quick-call.store')
            || ! Route::has('app.jobs.quick-call.confirmation')) {
            Route::get('/jobs/quick-call', [QuickCallController::class, 'create'])
                ->middleware(['web', 'auth', 'permission:jobs.create'])
                ->name('app.jobs.quick-call');
            Route::post('/jobs/quick-call', [QuickCallController::class, 'store'])
                ->middleware(['web', 'auth', 'permission:jobs.create'])
                ->name('app.jobs.quick-call.store');
            Route::get('/jobs/quick-call/{job}/confirmation', [QuickCallController::class, 'confirmation'])
                ->whereNumber('job')
                ->middleware(['web', 'auth', 'permission:jobs.create'])
                ->name('app.jobs.quick-call.confirmation');
            Route::getRoutes()->refreshNameLookups();
        }

        self::assertTrue(Route::has('app.jobs.quick-call'));
        self::assertTrue(Route::has('app.jobs.quick-call.store'));
        self::assertTrue(Route::has('app.jobs.quick-call.confirmation'));
    }

    public function test_dispatcher_can_register_a_quick_call_and_get_assign_and_detail_links(): void
    {
        [$operator, $ward] = $this->operatorWithWard();

        $this->actingAs($operator)
            ->get('/jobs/quick-call')
            ->assertOk()
            ->assertSee('Quick call')
            ->assertSee('Caller name')
            ->assertDontSee('Latitude');

        $response = $this->post('/jobs/quick-call', $this->quickCallData($ward));
        $job = EmergencyJob::query()->sole();

        $response->assertRedirect(route('app.jobs.quick-call.confirmation', $job));

        $this->assertSame('PHONE', $job->call_source);
        $this->assertSame(EmergencyJob::STATUS_RECEIVED, $job->status);
        $this->assertSame($ward->id, $job->ward_id);
        $this->assertSame('Central area', $job->society_name);
        $this->assertSame('45 Main Road', $job->incident_address);
        $this->assertSame('Smoke from a shop', $job->remarks);
        $this->assertDatabaseHas('job_call_logs', [
            'emergency_job_id' => $job->id,
            'caller_phone' => '+91 98765 43210',
            'created_by' => $operator->id,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job->id,
            'new_status' => EmergencyJob::STATUS_RECEIVED,
            'changed_by' => $operator->id,
        ]);

        $this->get(route('app.jobs.quick-call.confirmation', $job))
            ->assertOk()
            ->assertSee('registered successfully')
            ->assertSee('Assign one or more vehicles')
            ->assertSee(route('app.jobs.show', $job), false);
    }

    public function test_quick_call_requires_valid_fields_and_a_ward_in_the_dispatchers_scope(): void
    {
        [$operator, $ward] = $this->operatorWithWard();
        $otherWard = Ward::query()->create(['code' => 'WARD-OTHER', 'name' => 'Other Ward']);

        $this->actingAs($operator)
            ->get('/jobs/quick-call')
            ->assertOk()
            ->assertSee($ward->name)
            ->assertDontSee($otherWard->name);

        $this->from('/jobs/quick-call')
            ->post('/jobs/quick-call', $this->quickCallData($ward, [
                'caller_phone' => 'not a phone',
            ]))
            ->assertRedirect('/jobs/quick-call')
            ->assertSessionHasErrors('caller_phone');

        $this->from('/jobs/quick-call')
            ->post('/jobs/quick-call', $this->quickCallData($otherWard))
            ->assertRedirect('/jobs/quick-call')
            ->assertSessionHasErrors('ward_id');

        $this->assertDatabaseCount('emergency_jobs', 0);
    }

    public function test_confirmation_does_not_expose_another_incident_to_create_only_users(): void
    {
        [$operator, $ward] = $this->operatorWithWard();
        $this->actingAs($operator)
            ->post('/jobs/quick-call', $this->quickCallData($ward))
            ->assertRedirect();
        $job = EmergencyJob::query()->sole();

        $role = Role::query()->create([
            'code' => 'QUICK_CALL_ONLY',
            'name' => 'Quick call only',
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'jobs.create')->value('id'));
        $restrictedUser = User::factory()->create();
        $restrictedUser->roles()->attach($role);
        $operator->fireStations()->first()->users()->attach($restrictedUser->id);

        $this->actingAs($restrictedUser)
            ->get(route('app.jobs.quick-call.confirmation', $job))
            ->assertForbidden();
    }

    /**
     * @return array{User, Ward}
     */
    private function operatorWithWard(): array
    {
        $operator = User::factory()->create();
        $operator->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-QUICK',
            'name' => 'Quick Call Station',
            'address' => '1 Response Road',
        ]);
        $station->users()->attach($operator->id);
        $ward = Ward::query()->create(['code' => 'WARD-QUICK', 'name' => 'Quick Call Ward']);
        $station->wards()->attach($ward->id);

        return [$operator, $ward];
    }

    private function quickCallData(Ward $ward, array $overrides = []): array
    {
        return array_replace([
            'caller_name' => 'Quick Caller',
            'caller_phone' => '+91 98765 43210',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'ward_id' => $ward->id,
            'society_name' => 'Central area',
            'incident_address' => '45 Main Road',
            'remarks' => 'Smoke from a shop',
        ], $overrides);
    }
}
