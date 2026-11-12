<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\JobAssignmentAlert;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_users_can_view_and_mark_only_their_own_notifications_read(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $user->notify(new JobAssignmentAlert([
            'event' => 'assignment.status_changed',
            'job_id' => 'VMC-INBOX-01',
            'previous_status' => 'ASSIGNED',
            'new_status' => 'EN_ROUTE',
            'message' => 'Driver is en route.',
        ]));
        $otherUser->notify(new JobAssignmentAlert([
            'event' => 'assignment.status_changed',
            'job_id' => 'VMC-PRIVATE-01',
        ]));
        $notification = $user->notifications()->sole();

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('VMC-INBOX-01')
            ->assertSee('Driver is en route.')
            ->assertDontSee('VMC-PRIVATE-01')
            ->assertSee('1 unread notification');

        $this->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('notifications.0.job_id', 'VMC-INBOX-01')
            ->assertJsonMissing(['job_id' => 'VMC-PRIVATE-01']);

        $this->post(route('notifications.read', $notification->id))
            ->assertRedirect(route('notifications.index'));
        $this->assertNotNull($notification->fresh()->read_at);

        $this->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'));
    }
}
