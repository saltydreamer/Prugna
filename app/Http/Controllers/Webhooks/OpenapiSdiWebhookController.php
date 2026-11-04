<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSdiEvent;
use App\Models\SdiEvent;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Callbacks of Openapi SDI (configured by the "Collega" button in the settings): authenticated by the
 * secret Authorization header we gave Openapi. Events are stored and processed in the queue.
 */
class OpenapiSdiWebhookController extends Controller
{
    private const EVENTS = ['customer-invoice', 'customer-notification', 'supplier-invoice', 'invoice-status-quarantena', 'invoice-status-invoice-error', 'legal-storage-receipt', 'legal-storage-missing-vat'];

    public function __invoke(Request $request, Settings $settings): JsonResponse
    {
        $secret = (string) $settings->get('sdi.webhook_secret');
        $given = trim((string) preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization')));

        abort_if($secret === '' || ! hash_equals($secret, $given), 401);

        $payload = $request->json()->all();
        // Status callbacks carry no "event" key: every callback URL names its event in the query string.
        $name = (string) ($payload['event'] ?? $request->query('event', ''));

        if (! in_array($name, self::EVENTS, true)) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $event = SdiEvent::create(['event' => $name, 'payload' => $payload]);
        ProcessSdiEvent::dispatch($event->id);

        return response()->json(['received' => true]);
    }
}
