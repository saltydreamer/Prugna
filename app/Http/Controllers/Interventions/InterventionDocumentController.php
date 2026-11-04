<?php

namespace App\Http\Controllers\Interventions;

use App\Actions\Interventions\SignIntervention;
use App\Http\Controllers\Controller;
use App\Mail\DocumentMail;
use App\Models\Intervention;
use App\Support\InterventionPdf;
use App\Support\MailSettings;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Rapportino (PDF and email) and customer signature of an intervention.
 */
class InterventionDocumentController extends Controller
{
    public function pdf(Request $request, Intervention $intervention, InterventionPdf $pdf): Response
    {
        $this->authorize('view', $intervention);

        abort_if($intervention->isRunning(), 409, __("L'intervento è ancora in corso."));

        $document = $pdf->make($intervention);

        return $request->boolean('download')
            ? $document->download($pdf->filename($intervention))
            : $document->stream($pdf->filename($intervention));
    }

    /**
     * Defaults of the send dialog.
     */
    public function compose(Request $request, Intervention $intervention, Settings $settings, MailSettings $mail): JsonResponse
    {
        $this->authorize('send', $intervention);

        $intervention->loadMissing(['contact', 'organization.contacts']);
        $company = (string) ($settings->get('company.name') ?: config('app.name'));
        $greeting = $intervention->contact ? "Gentile {$intervention->contact->full_name}," : 'Buongiorno,';

        $suggestions = collect([$intervention->contact?->email, $intervention->organization?->email])
            ->merge($intervention->organization?->contacts->pluck('email') ?? [])
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'to' => array_values(array_filter([$intervention->contact?->email ?? $intervention->organization?->email])),
            'suggestions' => $suggestions,
            'subject' => __('Rapportino :number del :date – :company', ['number' => $intervention->number, 'date' => $intervention->localStart()->format('d/m/Y'), 'company' => $company]),
            'body' => implode("\n", [
                $greeting,
                '',
                __("in allegato il rapportino dell'intervento :number del :date.", ['number' => $intervention->number, 'date' => $intervention->localStart()->format('d/m/Y')]),
                __('Restiamo a disposizione per qualsiasi chiarimento.'),
                '',
                __('Cordiali saluti,'),
                $request->user()->name,
                $company,
            ]),
            'can_send' => $mail->canSend(),
        ]);
    }

    public function send(Request $request, Intervention $intervention, MailSettings $mailSettings, InterventionPdf $pdf): RedirectResponse
    {
        $this->authorize('send', $intervention);

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
                $request->boolean('attach_pdf', true) ? fn () => $pdf->make($intervention)->output() : null,
                $pdf->filename($intervention),
                $request->user()->email,
                $request->user()->name,
            ));
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $exception->getMessage()])]);

            return back();
        }

        $intervention->forceFill(['report_sent_at' => now()])->saveQuietly();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rapportino inviato a :to.', ['to' => implode(', ', $data['to'])])]);

        return back();
    }

    /**
     * Customer signature drawn on the device (PNG data URL): the intervention is then final.
     */
    public function sign(Request $request, Intervention $intervention, SignIntervention $sign): RedirectResponse
    {
        $this->authorize('sign', $intervention);

        $data = $request->validate([
            'signed_by_name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string', 'starts_with:'.SignIntervention::PREFIX, 'max:'.(int) (SignIntervention::MAX_BYTES * 1.4)],
        ], [], ['signed_by_name' => 'nome di chi firma', 'signature' => 'firma']);

        $sign->handle($intervention, $data['signed_by_name'], $data['signature']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rapportino firmato da :name.', ['name' => $data['signed_by_name']])]);

        return back();
    }

    public function unsign(Intervention $intervention): RedirectResponse
    {
        $this->authorize('unsign', $intervention);

        if ($intervention->signature_path) {
            Storage::disk('local')->delete($intervention->signature_path);
        }

        $intervention->forceFill(['signature_path' => null, 'signed_by_name' => null, 'signed_at' => null])->saveQuietly();

        Inertia::flash('toast', ['type' => 'success', 'message' => __("Firma rimossa: l'intervento si può di nuovo modificare.")]);

        return back();
    }

    /**
     * The signature image, for the intervention page.
     */
    public function signature(Intervention $intervention): Response
    {
        $this->authorize('view', $intervention);

        abort_unless($intervention->signature_path && Storage::disk('local')->exists($intervention->signature_path), 404);

        return Storage::disk('local')->response($intervention->signature_path, null, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600']);
    }
}
