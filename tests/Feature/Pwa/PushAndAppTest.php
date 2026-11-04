<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use App\Notifications\PushTestNotification;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class PushAndAppTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    public function test_the_manifest_and_the_service_worker_are_served(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.2.purpose', 'maskable');

        $script = $this->get('/sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')->getContent();
        $this->assertStringNotContainsString('__VERSION__', $script);
        $this->assertStringNotContainsString('__ASSETS__', $script);
        $this->assertStringContainsString("const OFFLINE_PAGES = ['/field'];", $script);
    }

    public function test_the_service_worker_refresh_never_becomes_the_page_after_login(): void
    {
        $this->get('/field', ['X-SW-Warm' => '1'])->assertUnauthorized();
        $this->assertNull(session('url.intended'));
    }

    public function test_devices_subscribe_and_receive_notifications(): void
    {
        $user = User::factory()->create();

        $key = $this->actingAs($user)->getJson('/profile/push/key')->assertOk()->json('public_key');
        $this->assertNotEmpty($key);
        $this->assertSame($key, $this->actingAs($user)->getJson('/profile/push/key')->json('public_key'), 'The keys are generated once.');
        $this->assertTrue(app(Settings::class)->has('push.vapid_private_key'));

        // Without a device: no push, and no test.
        $this->assertNotContains(WebPushChannel::class, (new PushTestNotification)->via($user));
        $this->actingAs($user)->postJson('/profile/push/test')->assertStatus(409);

        $this->actingAs($user)->postJson('/profile/push/subscriptions', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
            'content_encoding' => 'aes128gcm',
        ])->assertOk()->assertJsonPath('devices', 1);

        $this->assertContains(WebPushChannel::class, (new PushTestNotification)->via($user->fresh()));

        $message = (new PushTestNotification)->toWebPush($user)->toArray();
        $this->assertSame('Notifiche attive', $message['title']);
        $this->assertStringEndsWith('/notifications', $message['data']['url']);

        Notification::fake();
        $this->actingAs($user)->postJson('/profile/push/test')->assertOk();
        Notification::assertSentTo($user, PushTestNotification::class);

        $this->actingAs($user)->deleteJson('/profile/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertOk()->assertJsonPath('devices', 0);
    }

    public function test_notifications_are_pushed_encrypted_and_signed(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $user = User::factory()->create();
        $user->updatePushSubscription(self::ENDPOINT, 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');

        $user->notify(new PushTestNotification);

        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && $request->hasHeader('Content-Encoding', 'aes128gcm')
            && str_starts_with($request->header('Authorization')[0] ?? '', 'vapid t=')
            && strlen($request->body()) > 100);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $user->id, 'type' => 'PushTestNotification']);
    }

    public function test_push_endpoints_must_be_https(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/profile/push/subscriptions', [
            'endpoint' => 'http://example.com/push',
            'keys' => ['p256dh' => 'x', 'auth' => 'y'],
        ])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }
}
