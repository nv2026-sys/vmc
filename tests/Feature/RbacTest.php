<?php

namespace Tests\Feature;

use App\Models\FireStation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InitialSuperAdminSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_roles_grant_only_their_seeded_permissions(): void
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $this->assertTrue($dispatcher->hasRole('DISPATCH_OPERATOR'));
        $this->assertTrue($dispatcher->hasPermission('jobs.assign'));
        $this->assertFalse($dispatcher->hasPermission('settings.manage'));

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));

        $this->assertTrue($superAdmin->hasPermission('settings.manage'));
    }

    public function test_station_access_is_limited_to_assigned_stations(): void
    {
        $user = User::factory()->create();
        $firstStation = FireStation::query()->create([
            'code' => 'FS-001',
            'name' => 'Central Station',
            'address' => '1 Main Street',
        ]);
        $secondStation = FireStation::query()->create([
            'code' => 'FS-002',
            'name' => 'North Station',
            'address' => '2 North Road',
        ]);

        $user->fireStations()->attach($firstStation->id);

        $this->assertTrue($user->canAccessFireStation($firstStation));
        $this->assertFalse($user->canAccessFireStation($secondStation));

        $managedStation = FireStation::query()->create([
            'code' => 'FS-003',
            'name' => 'Managed Station',
            'address' => '3 South Road',
            'admin_user_id' => $user->id,
        ]);

        $this->assertTrue($user->canAccessFireStation($managedStation));
    }

    public function test_initial_super_admin_is_created_from_explicit_credentials(): void
    {
        config([
            'emergency.initial_super_admin.email' => 'admin@example.com',
            'emergency.initial_super_admin.password' => 'a-strong-initial-password',
        ]);

        $this->seed(InitialSuperAdminSeeder::class);
        $this->seed(InitialSuperAdminSeeder::class);

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('SUPER_ADMIN'));
        $this->assertTrue(Hash::check('a-strong-initial-password', $user->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_permission_middleware_enforces_role_permissions(): void
    {
        Route::middleware(['auth:sanctum', 'permission:jobs.assign'])
            ->get('/api/v1/permission-check', static fn () => response()->json(['allowed' => true]));

        $teamMember = User::factory()->create();
        $teamMember->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));

        $this->actingAs($teamMember, 'sanctum')
            ->getJson('/api/v1/permission-check')
            ->assertForbidden();

        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $this->actingAs($dispatcher, 'sanctum')
            ->getJson('/api/v1/permission-check')
            ->assertOk()
            ->assertJsonPath('allowed', true);
    }

    public function test_super_admin_can_create_a_custom_role_with_selected_permissions(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));

        $this->actingAs($superAdmin)
            ->get('/roles')
            ->assertOk()
            ->assertSee('+ Add role');

        $this->get(route('app.roles.create'))
            ->assertOk()
            ->assertSee('Create role')
            ->assertSee('jobs.view');

        $permission = Permission::query()->where('code', 'jobs.view')->firstOrFail();
        $this->post(route('app.roles.store'), [
            'name' => 'Incident Viewer',
            'code' => 'INCIDENT_VIEWER',
            'description' => 'View permitted incident information.',
            'permissions' => [$permission->id],
        ])
            ->assertRedirect(route('app.roles'))
            ->assertSessionHas('status', 'Incident Viewer role created.');

        $role = Role::query()->where('code', 'INCIDENT_VIEWER')->firstOrFail();
        $this->assertFalse($role->is_system);
        $this->assertTrue($role->permissions->contains('code', 'jobs.view'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'roles.created',
            'subject_id' => (string) $role->id,
        ]);

        $station = FireStation::query()->create([
            'code' => 'FS-CUSTOM-ROLE',
            'name' => 'Custom Role Station',
            'address' => '1 Access Road',
        ]);
        $this->get('/users/create')
            ->assertOk()
            ->assertSee('Incident Viewer');
        $this->post('/users', [
            'name' => 'Incident Viewer User',
            'email' => 'incident.viewer@example.test',
            'role_code' => 'INCIDENT_VIEWER',
            'password' => 'secure-custom-role-password',
            'station_ids' => [$station->id],
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('user_roles', [
            'user_id' => User::query()->where('email', 'incident.viewer@example.test')->value('id'),
            'role_id' => $role->id,
        ]);
    }

    public function test_only_users_with_role_management_permission_can_create_roles(): void
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $this->actingAs($dispatcher)
            ->get('/roles/create')
            ->assertForbidden();

        $this->post('/roles', [
            'name' => 'Unauthorized Role',
            'code' => 'UNAUTHORIZED_ROLE',
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['code' => 'UNAUTHORIZED_ROLE']);
    }
}
