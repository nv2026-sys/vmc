<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\DispatchAssigned;
use App\Notifications\JobAssignmentAlert;
use App\Services\FirebaseAccessTokenProvider;
use App\Services\FirebaseMessagingService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FcmDeviceTokenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_and_refresh_fcm_device_tokens(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/save-fcm-token', [
            'fcm_token' => 'test-fcm-token-android-123',
            'device_type' => 'android',
        ])
            ->assertOk()
            ->assertJsonPath('data.device_type', 'android')
            ->assertJsonMissingPath('data.fcm_token');

        $device = UserDevice::query()->sole();
        $this->assertSame('test-fcm-token-android-123', $device->fcm_token);
        $this->assertNotSame('test-fcm-token-android-123', $device->getRawOriginal('fcm_token'));

        $this->postJson('/api/save-fcm-token', [
            'fcm_token' => 'test-fcm-token-android-123',
            'device_type' => 'ios',
        ])->assertOk();

        $this->assertSame(1, UserDevice::query()->count());
        $this->assertSame('ios', $device->fresh()->device_type);

        $this->postJson('/api/save-fcm-token', [
            'fcm_token' => 'test-fcm-token-ios-456',
            'device_type' => 'ios',
        ])->assertOk();

        $this->assertSame(2, UserDevice::query()->count());
    }

    public function test_fcm_device_token_registration_requires_authentication_and_valid_device_type(): void
    {
        $this->postJson('/api/save-fcm-token', [
            'fcm_token' => 'test-fcm-token',
            'device_type' => 'android',
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/save-fcm-token', [
                'fcm_token' => 'test-fcm-token',
                'device_type' => 'windows',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device_type');
    }

    public function test_dispatch_notifications_queue_fcm_with_flutter_screen_data(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'fcm_token_hash' => hash('sha256', 'test-fcm-token'),
            'fcm_token' => 'test-fcm-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);
        UserDevice::query()->create([
            'user_id' => $otherUser->id,
            'fcm_token_hash' => hash('sha256', 'other-test-fcm-token'),
            'fcm_token' => 'other-test-fcm-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);
        config([
            'services.firebase.project_id' => 'test-project',
            'services.firebase.credentials' => 'server-only-key.json',
        ]);
        Queue::fake();

        $user->notify(new DispatchAssigned([
            'job_id' => 'VMC-FCM-01',
            'assignment_id' => 42,
            'driver_user_id' => $user->id,
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'incident_address' => '12 Sample Street',
            'vehicle_number' => 'FIRE-01',
            'status' => 'ASSIGNED',
            'message' => 'A new incident is assigned.',
        ]));
        $otherUser->notify(new DispatchAssigned([
            'job_id' => 'VMC-FCM-01',
            'assignment_id' => 42,
            'driver_user_id' => $user->id,
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'incident_address' => '12 Sample Street',
            'vehicle_number' => 'FIRE-01',
            'status' => 'ASSIGNED',
            'message' => 'A new incident is assigned.',
        ]));

        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->deviceId === $device->id
            && $job->message['title'] === 'New dispatch assignment'
            && $job->message['body'] === 'A new incident is assigned.'
            && $job->message['data'] === [
                'event' => 'dispatch.assigned',
                'screen' => 'incident_detail',
                'job_id' => 'VMC-FCM-01',
                'assignment_id' => '42',
                'incident_type' => 'STRUCTURE_FIRE',
                'priority' => 'HIGH',
                'incident_address' => '12 Sample Street',
                'vehicle_number' => 'FIRE-01',
                'status' => 'ASSIGNED',
                'driver_user_id' => (string) $user->id,
            ]);
        Queue::assertPushed(SendFcmNotification::class, 1);
    }

    public function test_backend_fcm_test_command_sends_to_the_selected_users_device(): void
    {
        $user = User::factory()->create();
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'fcm_token_hash' => hash('sha256', 'test-fcm-token'),
            'fcm_token' => 'test-fcm-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);

        $this->mock(FirebaseMessagingService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (UserDevice $sentDevice, array $message): bool => $sentDevice->is($device)
                && $message['title'] === 'FCM test notification'
                && $message['data']['event'] === 'fcm.test')
            ->andReturn('projects/test-project/messages/123');

        $exitCode = Artisan::call('fcm:test', ['user' => (string) $user->id]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'projects/test-project/messages/123',
            Artisan::output(),
        );
    }

    public function test_assignment_status_push_is_limited_to_the_assigned_driver(): void
    {
        $driver = User::factory()->create();
        $otherUser = User::factory()->create();
        $driverDevice = UserDevice::query()->create([
            'user_id' => $driver->id,
            'fcm_token_hash' => hash('sha256', 'driver-status-token'),
            'fcm_token' => 'driver-status-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);
        UserDevice::query()->create([
            'user_id' => $otherUser->id,
            'fcm_token_hash' => hash('sha256', 'other-status-token'),
            'fcm_token' => 'other-status-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);
        config([
            'services.firebase.project_id' => 'test-project',
            'services.firebase.credentials' => 'server-only-key.json',
        ]);
        Queue::fake();

        $statusNotification = new JobAssignmentAlert([
            'event' => 'assignment.status_changed',
            'fcm_target_user_id' => $driver->id,
            'assignment_id' => 42,
            'job_id' => 'VMC-FCM-STATUS',
            'previous_status' => 'ASSIGNED',
            'new_status' => 'EN_ROUTE',
            'message' => 'Assignment status updated.',
        ]);
        $driver->notify($statusNotification);
        $otherUser->notify($statusNotification);

        Queue::assertPushed(SendFcmNotification::class, 1);
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->deviceId === $driverDevice->id
                && $job->message['data']['new_status'] === 'EN_ROUTE',
        );
    }

    public function test_firebase_messaging_uses_http_v1_and_removes_unregistered_tokens(): void
    {
        $device = UserDevice::query()->create([
            'user_id' => User::factory()->create()->id,
            'fcm_token_hash' => hash('sha256', 'test-fcm-token'),
            'fcm_token' => 'test-fcm-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);
        config(['services.firebase.project_id' => 'test-project']);
        $this->mock(FirebaseAccessTokenProvider::class)
            ->shouldReceive('getToken')
            ->once()
            ->andReturn('test-access-token');
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
                'error' => ['status' => 'UNREGISTERED'],
            ], 404),
        ]);

        app(FirebaseMessagingService::class)->send($device, [
            'title' => 'New assignment',
            'body' => 'A new incident is assigned.',
            'data' => [
                'event' => 'dispatch.assigned',
                'screen' => 'incident_detail',
            ],
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['message']['token'] === 'test-fcm-token'
            && $request['message']['data']['screen'] === 'incident_detail'
            && $request['message']['data']['title'] === 'New assignment'
            && $request['message']['data']['body'] === 'A new incident is assigned.');
        $this->assertDatabaseMissing('user_devices', ['id' => $device->id]);
    }

    public function test_firebase_messaging_returns_the_message_name_on_success(): void
    {
        $device = UserDevice::query()->create([
            'user_id' => User::factory()->create()->id,
            'fcm_token_hash' => hash('sha256', 'success-test-fcm-token'),
            'fcm_token' => 'success-test-fcm-token',
            'device_type' => 'android',
            'last_seen_at' => now(),
        ]);
        config(['services.firebase.project_id' => 'test-project']);
        $this->mock(FirebaseAccessTokenProvider::class)
            ->shouldReceive('getToken')
            ->once()
            ->andReturn('test-access-token');
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
                'name' => 'projects/test-project/messages/123',
            ], 200),
        ]);

        $messageName = app(FirebaseMessagingService::class)->send($device, [
            'title' => 'FCM test',
            'body' => 'Test message',
            'data' => ['event' => 'fcm.test'],
        ]);

        $this->assertSame('projects/test-project/messages/123', $messageName);
        $this->assertDatabaseHas('user_devices', ['id' => $device->id]);
    }
}
