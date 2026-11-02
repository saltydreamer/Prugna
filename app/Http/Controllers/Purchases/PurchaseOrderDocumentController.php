<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Mail\DocumentMail;
use App\Models\PurchaseOrder;
use App\Support\MailSettings;
use App\Support\PurchaseOrderPdf;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * PDF and email of a supplier order.
 */
class PurchaseOrderDocumentController extends Controller
{
    public function pdf(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderPdf $pdf): Response
    {
        $this->authorize('view', $purchaseOrder);

        $document = $pdf->make($purchaseOrder);

        return $request->boolean('download')
            ? $document->download($pdf->filename($purchaseOrder))
            : $document->stream($pdf->filename($purchaseOrder));
    }

    /**
     * Defaults of the send dialog.
     */
    public function compose(Request $request, PurchaseOrder $purchaseOrder, Settings $settings, MailSettings $mail): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        $purchaseOrder->loadMissing(['contact', 'supplierOrganization.contacts']);
        $company = (string) ($settings->get('company.name') ?: config('app.name'));
        $greeting = $purchaseOrder->contact ? "Gentile {$purchaseOrder->contact->full_name}," : 'Buongiorno,';

        $suggestions = collect([$purchaseOrder->contact?->email, $purchaseOrder->supplier['email'] ?? null])
            ->merge($purchaseOrder->supplierOrganization?->contacts->pluck('email') ?? [])
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'to' => array_values(array_filter([$purchaseOrder->contact?->email ?? ($purchaseOrder->supplier['email'] ?? null)])),
            'suggestions' => $suggestions,
            'subject' => __('Ordine :number – :company', ['number' => $purchaseOrder->number, 'company' => $company]),
            'body' => implode("\n", array_filter([
                $greeting,
                '',
                __('in allegato il nostro ordine :number.', ['number' => $purchaseOrder->number]),
                $purchaseOrder->expected_date ? __('Consegna richiesta entro il :date.', ['date' => $purchaseOrder->expected_date->format('d/m/Y')]) : null,
                __("Vi chiediamo cortesemente conferma d'ordine con i tempi di consegna."),
                '',
                __('Cordiali saluti,'),
                $request->user()->name,
                $company,
            ], fn (?string $line) => $line !== null)),
            'can_send' => $mail->canSend(),
        ]);
    }

    public function send(Request $request, PurchaseOrder $purchaseOrder, MailSettings $mailSettings, PurchaseOrderPdf $pdf): RedirectResponse
    {
        $this->authorize('update', $purchaseOrder);
        abort_if($purchaseOrder->status === PurchaseOrderStatus::Cancelled, 409, __("L'ordine è annullato."));

        $data = $request->validate([
            'to' => ['required', 'array', 'min:1', 'max:10'],
            'to.*' => ['required', 'email'],
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'attach_pdf' => ['boolean'],
        ], [], ['to' => 'destinatari', 'to.*' => 'destinatario', 'cc.*' => 'copia', 'subject' => 'oggetto', 'body' => 'testo']);

        if (! $mailSettings->canSend()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Configura il server di posta in Impostazioni → Email prima di inviare.')]);

            return back();
        }

        try {
            Mail::to($data['to'])->cc($data['cc'] ?? [])->send(new DocumentMail(
                $data['subject'],
                $data['body'],
                $request->boolean('attach_pdf', true) ? fn () => $pdf->make($purchaseOrder)->output() : null,
                $pdf->filename($purchaseOrder),
                $request->user()->email,
                $request->user()->name,
            ));
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $exception->getMessage()])]);

            return back();
        }

        if ($purchaseOrder->status === PurchaseOrderStatus::Draft) {
            $purchaseOrder->forceFill(['status' => PurchaseOrderStatus::Sent]);
        }

        $purchaseOrder->forceFill(['sent_at' => $purchaseOrder->sent_at ?? now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ordine inviato a :to.', ['to' => implode(', ', $data['to'])])]);

        return back();
    }
}
