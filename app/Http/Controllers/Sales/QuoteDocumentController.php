<?php

namespace App\Http\Controllers\Sales;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Mail\QuoteMail;
use App\Models\Quote;
use App\Support\MailSettings;
use App\Support\QuoteMessage;
use App\Support\QuotePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * PDF and email of a quote.
 */
class QuoteDocumentController extends Controller
{
    public function pdf(Request $request, Quote $quote, QuotePdf $pdf): Response
    {
        $this->authorize('view', $quote);

        $document = $pdf->make($quote);

        return $request->boolean('download')
            ? $document->download($pdf->filename($quote))
            : $document->stream($pdf->filename($quote));
    }

    /**
     * Data for the send dialog: recipients, subject and text filled from the template.
     */
    public function compose(Request $request, Quote $quote, QuoteMessage $message, MailSettings $mail): JsonResponse
    {
        $this->authorize('update', $quote);

        $quote->loadMissing(['contact', 'organization.contacts']);

        $suggestions = collect([$quote->contact?->email, $quote->customer['email'] ?? null, $quote->customer['pec'] ?? null])
            ->merge($quote->organization?->contacts->pluck('email') ?? [])
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            ...$message->defaults($quote, $request->user()),
            'to' => array_values(array_filter([$quote->contact?->email ?? ($quote->customer['email'] ?? null)])),
            'suggestions' => $suggestions,
            'can_send' => $mail->canSend(),
        ]);
    }

    public function send(Request $request, Quote $quote, MailSettings $mailSettings): RedirectResponse
    {
        $this->authorize('update', $quote);
        abort_unless(in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true), 409, __('Il preventivo non può essere inviato in questo stato.'));

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
            Mail::to($data['to'])
                ->cc($data['cc'] ?? [])
                ->send(new QuoteMail(
                    $quote,
                    $data['subject'],
                    $data['body'],
                    $request->boolean('attach_pdf', true),
                    $request->user()->email,
                    $request->user()->name,
                ));
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $exception->getMessage()])]);

            return back();
        }

        $quote->markSent();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preventivo inviato a :to.', ['to' => implode(', ', $data['to'])])]);

        return back();
    }
}
