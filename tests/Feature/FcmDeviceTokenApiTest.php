<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\DispatchAssigned;
use App\Services\FirebaseAccessTokenProvider;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
        $device = UserDevice::query()->create([
            'user_id' => $user->id,
            'fcm_token_hash' => hash('sha256', 'test-fcm-token'),
            'fcm_token' => 'test-fcm-token',
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
            'message' => 'A new incident is assigned.',
        ]));

        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->deviceId === $device->id
            && $job->message['data'] === [
                'event' => 'dispatch.assigned',
                'screen' => 'incident_detail',
                'job_id' => 'VMC-FCM-01',
                'assignment_id' => '42',
            ]);
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
            && $request['message']['data']['screen'] === 'incident_detail');
        $this->assertDatabaseMissing('user_devices', ['id' => $device->id]);
    }
}
