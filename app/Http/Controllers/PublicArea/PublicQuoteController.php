<?php

namespace App\Http\Controllers\PublicArea;

use App\Actions\Quotes\CreateHourPackages;
use App\Actions\Quotes\QuoteTotals;
use App\Actions\Quotes\SaveQuote;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Notifications\QuoteActivityNotification;
use App\Support\QuotePdf;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Page reached by the customer through the link in the email: no login, the token is the key.
 */
class PublicQuoteController extends Controller
{
    public function show(Request $request, string $token, Settings $settings): Response
    {
        $quote = $this->find($token);

        // First visit of the customer (not of a logged-in colleague previewing it).
        if ($quote->viewed_at === null && $quote->status === QuoteStatus::Sent && ! $request->user()) {
            $quote->forceFill(['viewed_at' => now()])->save();
            $quote->owner?->notify(new QuoteActivityNotification($quote, QuoteActivityNotification::VIEWED));
        }

        $quote->load(['lines', 'contact', 'owner']);
        $company = $settings->group('company');

        return Inertia::render('public/Quote', [
            'quote' => [
                'number' => $quote->number,
                'status' => $quote->status->value,
                'status_label' => $quote->status->label(),
                'title' => $quote->title,
                'customer' => $quote->customer,
                'contact' => $quote->contact?->full_name,
                'issue_date' => $quote->issue_date->toDateString(),
                'valid_until' => $quote->valid_until->toDateString(),
                'is_open' => $quote->isOpen(),
                'intro' => $quote->intro,
                'terms' => $quote->terms,
                'payment_terms' => $quote->payment_terms,
                'decided_at' => $quote->decided_at?->toIso8601String(),
                'decision_name' => $quote->decision_name,
                'owner' => $quote->owner?->only(['name', 'email']),
                'lines' => $quote->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'type' => $line->type->value,
                    'code' => $line->code,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'discount_percent' => (float) $line->discount_percent,
                    'vat_rate' => (float) $line->vat_rate,
                    'vat_nature' => $line->vat_nature,
                    'is_optional' => $line->is_optional,
                    'is_selected' => $line->is_selected,
                    'package_hours' => $line->package_hours !== null ? (float) $line->package_hours : null,
                    'package_months' => $line->package_months,
                    'total' => (float) $line->total,
                ])->values(),
                'totals' => QuoteTotals::summarize($quote->lines),
            ],
            'company' => [
                'name' => $company['name'] ?? config('app.name'),
                'logo_url' => ! empty($company['logo_path']) ? Storage::disk('public')->url($company['logo_path']) : null,
                'email' => $company['email'] ?? null,
                'phone' => $company['phone'] ?? null,
                'vat_number' => $company['vat_number'] ?? null,
            ],
            'token' => $token,
        ]);
    }

    /**
     * The customer accepts, choosing the optional lines to include.
     */
    public function accept(Request $request, string $token, SaveQuote $saveQuote): RedirectResponse
    {
        $quote = $this->find($token);
        abort_unless($quote->isOpen(), 409, __('Questo preventivo non può più essere accettato.'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'optional_lines' => ['nullable', 'array'],
            'optional_lines.*' => ['integer'],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => __('Conferma di accettare il preventivo e le sue condizioni.'),
        ], ['name' => 'nome e cognome', 'note' => 'note']);

        DB::transaction(function () use ($quote, $data, $request, $saveQuote): void {
            $selected = collect($data['optional_lines'] ?? [])->map(fn ($id) => (int) $id);

            foreach ($quote->lines()->where('is_optional', true)->get() as $line) {
                $line->forceFill(['is_selected' => $selected->contains($line->id)])->save();
            }

            $saveQuote->refreshTotals($quote);
            $quote->decide(true, $data['name'], $data['note'] ?? null, $request->ip());
            app(CreateHourPackages::class)->handle($quote);
        });

        $quote->owner?->notify(new QuoteActivityNotification($quote, QuoteActivityNotification::ACCEPTED));

        return back();
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $quote = $this->find($token);
        abort_unless($quote->isOpen(), 409, __('Questo preventivo non può più essere modificato.'));

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $quote->decide(false, $data['name'] ?? null, $data['note'] ?? null, $request->ip());
        $quote->owner?->notify(new QuoteActivityNotification($quote, QuoteActivityNotification::REJECTED));

        return back();
    }

    public function pdf(string $token, QuotePdf $pdf): HttpResponse
    {
        $quote = $this->find($token);

        return $pdf->make($quote)->download($pdf->filename($quote));
    }

    /**
     * Drafts are never public.
     */
    private function find(string $token): Quote
    {
        return Quote::query()
            ->where('public_token', $token)
            ->where('status', '!=', QuoteStatus::Draft->value)
            ->firstOrFail();
    }
}
