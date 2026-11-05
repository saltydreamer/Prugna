<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Invoices\IssueInvoice;
use App\Actions\Sdi\SendInvoiceToSdi;
use App\Enums\SdiStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSdiEvent;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SdiEvent;
use App\Support\CustomerSnapshot;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Sending an issued invoice to the SDI and reading its outcome.
 */
class InvoiceSdiController extends Controller
{
    public function send(Request $request, Invoice $invoice, SendInvoiceToSdi $send, OpenapiClient $client, IssueInvoice $issueInvoice): RedirectResponse
    {
        $this->authorize('sendToSdi', $invoice);

        if (! $client->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Configura la fatturazione elettronica in Impostazioni → Fatturazione elettronica.')]);

            return back();
        }

        if ($mismatch = $client->fiscalIdMismatch()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => self::mismatchMessage($mismatch)]);

            return back();
        }

        if (! $invoice->sdiTransmissions()->exists() && ($problems = $issueInvoice->sendProblems($invoice))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $problems[0]]);

            return back();
        }

        $transmission = $send->handle($invoice, $request->user());

        Inertia::flash('toast', $transmission->status === SdiStatus::Error
            ? ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $transmission->message])]
            : ['type' => 'success', 'message' => __('Fattura :number inviata allo SDI: lo stato si aggiorna da solo quando arriva l\'esito.', ['number' => $invoice->number])]);

        return back();
    }

    /**
     * @param  array{connected: string, company: string}  $mismatch
     */
    public static function mismatchMessage(array $mismatch): string
    {
        return __('La partita IVA dell\'azienda (:company) non è quella collegata a Openapi (:connected): in Impostazioni → Fatturazione elettronica premi «Collega», poi invia.', $mismatch);
    }

    /**
     * Marks an issued invoice as transmitted outside Prugna, or as still to send (e.g. an invoice
     * imported from Dolibarr that never left): in that case the customer data on the invoice
     * are refreshed, since nothing has been transmitted yet.
     */
    public function external(Request $request, Invoice $invoice): RedirectResponse
    {
        $external = (bool) $request->validate(['external' => ['required', 'boolean']])['external'];
        $this->authorize($external ? 'markSentOutside' : 'markToSend', $invoice);

        $invoice->forceFill([
            'sdi_external' => $external,
            ...($external ? [] : ['customer' => CustomerSnapshot::from(Organization::withTrashed()->findOrFail($invoice->organization_id))]),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $external
            ? __('Fattura :number segnata come già trasmessa allo SDI: Prugna non la invierà.', ['number' => $invoice->number])
            : __('Fattura :number segnata come da inviare allo SDI.', ['number' => $invoice->number])]);

        return back();
    }

    /**
     * Reads status and notifications of the latest transmission from Openapi (when a callback was missed).
     */
    public function refresh(Invoice $invoice, OpenapiClient $client): RedirectResponse
    {
        $this->authorize('view', $invoice);

        $transmission = $invoice->sdiTransmission;
        abort_unless($transmission?->uuid, 409, __('La fattura non è ancora stata inviata allo SDI.'));

        try {
            $client->forInvoice($invoice->id);
            $remote = $client->invoice($transmission->uuid);
            $notifications = $client->notifications($transmission->uuid);
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
        } elseif (($status = SdiStatus::fromMarking($remote['marking'] ?? null)) && $status !== $transmission->status) {
            $transmission->forceFill(['status' => $status, 'message' => is_string($remote['notice'] ?? null) ? $remote['notice'] : $transmission->message])->save();
            $invoice->forceFill(['sdi_status' => $status])->save();
        }

        if (isset($remote['sdi_file_id']) || isset($remote['sdi_file_name'])) {
            $transmission->refresh()->forceFill([
                'sdi_file_id' => isset($remote['sdi_file_id']) ? (string) $remote['sdi_file_id'] : $transmission->sdi_file_id,
                'filename' => is_string($remote['sdi_file_name'] ?? null) ? mb_substr($remote['sdi_file_name'], 0, 64) : $transmission->filename,
            ])->save();
        }

        $status = $invoice->fresh()->sdi_status;

        Inertia::flash('toast', ['type' => 'success', 'message' => in_array($status, [SdiStatus::Sent, SdiStatus::Pending], true)
            ? __('Nessun esito ancora: lo SDI può impiegare fino a qualche ora.')
            : __('Stato aggiornato: :status.', ['status' => $status?->label()])]);

        return back();
    }

    /**
     * Sandbox only: asks Openapi to simulate an SDI notification for the latest transmission.
     */
    public function simulate(Request $request, Invoice $invoice, OpenapiClient $client): RedirectResponse
    {
        $this->authorize('sendToSdi', $invoice);

        $data = $request->validate(['notification' => ['required', Rule::in(['RC', 'MC', 'NS', 'DT', 'AT'])]]);
        $transmission = $invoice->sdiTransmission;
        abort_unless($transmission?->uuid && $transmission->environment === 'test' && $client->isTest(), 409, __('La simulazione è possibile solo per le fatture inviate in prova.'));

        try {
            $client->forInvoice($invoice->id)->simulate('customer-notification', ['uuid' => $transmission->uuid, 'notification' => $data['notification']]);
        } catch (OpenapiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Esito simulato richiesto: arriva tra pochi secondi, poi aggiorna la pagina.')]);

        return back();
    }
}
