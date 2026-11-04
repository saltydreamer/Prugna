<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Notifications\PushTestNotification;
use App\Support\WebPushKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Push notifications on the user's devices (one subscription per browser / installed app).
 */
class PushSubscriptionController extends Controller
{
    /**
     * The public VAPID key the browser needs to subscribe.
     */
    public function key(WebPushKeys $keys): JsonResponse
    {
        return response()->json(['public_key' => $keys->publicKey()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['content_encoding'] ?? 'aes128gcm');

        return response()->json(['subscribed' => true, 'devices' => $request->user()->pushSubscriptions()->count()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH]]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['subscribed' => false, 'devices' => $request->user()->pushSubscriptions()->count()]);
    }

    /**
     * A test notification to every device of the user.
     */
    public function test(Request $request): JsonResponse
    {
        abort_unless($request->user()->pushSubscriptions()->exists(), 409, __('Attiva prima le notifiche su questo dispositivo.'));

        $request->user()->notify(new PushTestNotification);

        return response()->json(['sent' => true]);
    }
}
