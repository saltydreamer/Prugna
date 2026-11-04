<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sdi\SendInvoiceToSdi;
use App\Enums\SdiStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSdiEvent;
use App\Models\Invoice;
use App\Models\SdiEvent;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Sending an issued invoice to the SDI and reading its outcome.
 */
class InvoiceSdiController extends Controller
{
    public function send(Request $request, Invoice $invoice, SendInvoiceToSdi $send, OpenapiClient $client): RedirectResponse
    {
        $this->authorize('sendToSdi', $invoice);

        if (! $client->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Configura la fatturazione elettronica in Impostazioni → Fatturazione elettronica.')]);

            return back();
        }

        $transmission = $send->handle($invoice, $request->user());

        Inertia::flash('toast', $transmission->status === SdiStatus::Error
            ? ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $transmission->message])]
            : ['type' => 'success', 'message' => __('Fattura :number inviata allo SDI: lo stato si aggiorna da solo quando arriva l\'esito.', ['number' => $invoice->number])]);

        return back();
    }

    /**
     * Reads the notifications of the latest transmission (when a callback was missed).
     */
    public function refresh(Invoice $invoice, OpenapiClient $client): RedirectResponse
    {
        $this->authorize('view', $invoice);

        $transmission = $invoice->sdiTransmission;
        abort_unless($transmission?->uuid, 409, __('La fattura non è ancora stata inviata allo SDI.'));

        try {
            $notifications = $client->forInvoice($invoice->id)->notifications($transmission->uuid);
        } catch (OpenapiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        $latest = collect($notifications)->sortBy(fn (array $notification) => $notification['created_at'] ?? '')->last();

        if ($latest) {
            $event = SdiEvent::create([
                'event' => 'customer-notification',
                'payload' => ['event' => 'customer-notification', 'source' => 'poll', 'data' => ['notification' => ['invoice_uuid' => $transmission->uuid, ...$latest]]],
            ]);
            ProcessSdiEvent::dispatchSync($event->id);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $latest
            ? __('Stato aggiornato: :status.', ['status' => $invoice->fresh()->sdi_status?->label()])
            : __('Nessun esito ancora: lo SDI può impiegare fino a qualche ora.')]);

        return back();
    }
}
