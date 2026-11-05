<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CompanySettingsRequest;
use App\Http\Requests\Settings\MailSettingsRequest;
use App\Mail\TestMail;
use App\Models\AiExtraction;
use App\Models\PaymentTerm;
use App\Models\VatRate;
use App\Support\Ai\AiException;
use App\Support\Ai\DocumentReader;
use App\Support\Ai\OpenRouterClient;
use App\Support\Fiscal\FiscalCodes;
use App\Support\Sdi\OpenapiClient;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Impostazioni: company data, outgoing email (SMTP), quotes defaults, payment terms, AI.
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        $company = $this->settings->group('company');
        $mail = $this->settings->group('mail');
        unset($mail['password']);

        return Inertia::render('settings/Index', [
            'tab' => $request->query('tab', 'company'),
            'company' => [
                ...$company,
                'logo_url' => ! empty($company['logo_path']) ? Storage::disk('public')->url($company['logo_path']) : null,
            ],
            'mail' => [
                ...$mail,
                'has_password' => $this->settings->has('mail.password'),
            ],
            'quotes' => $this->settings->group('quotes'),
            'ai' => [
                'enabled' => (bool) $this->settings->get('ai.enabled'),
                'model' => $this->settings->get('ai.model'),
                'privacy' => $this->settings->get('ai.privacy'),
                'has_api_key' => $this->settings->has('ai.api_key'),
            ],
            'aiPrivacyLevels' => collect(OpenRouterClient::PRIVACY_LEVELS)->map(fn (string $title, string $value) => ['value' => $value, 'title' => $title])->values(),
            'aiUsage' => Inertia::optional(fn () => $this->aiUsage()),
            'fiscal' => $this->settings->group('fiscal'),
            'fiscalOptions' => [
                'regimes' => FiscalCodes::options(FiscalCodes::REGIMES),
                'withholdingTypes' => FiscalCodes::options(FiscalCodes::WITHHOLDING_TYPES),
                'withholdingReasons' => FiscalCodes::options(FiscalCodes::WITHHOLDING_REASONS),
                'pensionFunds' => FiscalCodes::options(FiscalCodes::PENSION_FUNDS),
                'vatRates' => VatRate::query()->active()->ordered()->get()->map(fn (VatRate $rate) => ['value' => $rate->id, 'title' => $rate->name]),
            ],
            'paymentTerms' => PaymentTerm::query()->withCount('organizations')->ordered()->get(),
            'paymentMethods' => collect(PaymentTerm::METHODS)->map(fn (string $title, string $value) => ['value' => $value, 'title' => "{$value} – {$title}"])->values(),
        ]);
    }

    public function updateCompany(CompanySettingsRequest $request): RedirectResponse
    {
        $this->settings->set(collect($request->validated())->mapWithKeys(fn ($value, $key) => ["company.{$key}" => $value])->all());

        // Openapi knows the company by its VAT number: a new one must be registered again.
        Inertia::flash('toast', app(OpenapiClient::class)->fiscalIdMismatch()
            ? ['type' => 'warning', 'message' => __('Dati azienda salvati. La partita IVA è cambiata: in Impostazioni → Fatturazione elettronica premi «Collega», altrimenti Openapi rifiuta le fatture.')]
            : ['type' => 'success', 'message' => __('Dati azienda salvati.')]);

        return back();
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ]);

        $previous = $this->settings->get('company.logo_path');
        $this->settings->set(['company.logo_path' => $request->file('logo')->store('company', 'public')]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Logo aggiornato.')]);

        return back();
    }

    public function destroyLogo(): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        if ($path = $this->settings->get('company.logo_path')) {
            Storage::disk('public')->delete($path);
        }

        $this->settings->set(['company.logo_path' => null]);

        return back();
    }

    public function updateMail(MailSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["mail.{$key}" => $value])->all());

        // Queue workers keep the mailer in memory: restart them to use the new server.
        Artisan::call('queue:restart');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni email salvate. Invia un\'email di prova per verificarle.')]);

        return back();
    }

    public function updateAi(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'enabled' => ['boolean'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'privacy' => ['required', Rule::in(array_keys(OpenRouterClient::PRIVACY_LEVELS))],
        ], [], ['api_key' => 'chiave API', 'model' => 'modello', 'privacy' => 'privacy']);

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        if ($request->boolean('enabled') && ! isset($data['api_key']) && ! $this->settings->has('ai.api_key')) {
            return back()->withErrors(['api_key' => __('Inserisci la chiave API di OpenRouter.')]);
        }

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["ai.{$key}" => $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni AI salvate.')]);

        return back();
    }

    /**
     * Models available on OpenRouter for reading documents (images + JSON schema), cheapest first.
     */
    public function aiModels(Request $request, OpenRouterClient $client): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $request->validate(['api_key' => ['nullable', 'string', 'max:255']]);

        try {
            return response()->json(['data' => $client->models($request->boolean('refresh'), $request->input('api_key') ?: null)]);
        } catch (AiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Reads a sample document with the chosen model and returns what was extracted, cost and time.
     */
    public function testAi(Request $request, DocumentReader $reader): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,pdf'],
            'kind' => ['required', Rule::in([AiExtraction::KIND_EXPENSE, AiExtraction::KIND_ORGANIZATION])],
            'model' => ['nullable', 'string', 'max:255'],
        ], [], ['file' => 'documento', 'model' => 'modello']);

        $extraction = $reader->read($request->file('file'), $data['kind'], $request->user(), $data['model'] ?: null);

        return response()->json([
            'ok' => $extraction->succeeded(),
            'error' => $extraction->error,
            'result' => $extraction->result,
            'model' => $extraction->model,
            'cost' => $extraction->cost !== null ? (float) $extraction->cost : null,
            'duration_ms' => $extraction->duration_ms,
            'tokens' => ['prompt' => $extraction->prompt_tokens, 'completion' => $extraction->completion_tokens],
        ], $extraction->succeeded() ? 200 : 422);
    }

    /**
     * Readings of the current month and the latest ones.
     *
     * @return array<string, mixed>
     */
    private function aiUsage(): array
    {
        $month = AiExtraction::query()->where('created_at', '>=', now()->startOfMonth());

        return [
            'month_count' => (clone $month)->where('source', 'ai')->count(),
            'month_cost' => round((float) (clone $month)->sum('cost'), 4),
            'latest' => AiExtraction::query()->with('user:id,name')->latest('id')->limit(10)->get()
                ->map(fn (AiExtraction $extraction) => [
                    'id' => $extraction->id,
                    'created_at' => $extraction->created_at?->toIso8601String(),
                    'user' => $extraction->user?->name,
                    'kind' => $extraction->kind,
                    'source' => $extraction->source,
                    'file' => $extraction->original_name,
                    'model' => $extraction->model,
                    'status' => $extraction->status,
                    'error' => $extraction->error,
                    'cost' => $extraction->cost !== null ? (float) $extraction->cost : null,
                    'duration_ms' => $extraction->duration_ms,
                ]),
        ];
    }

    /**
     * Sends a test email to the chosen address, synchronously, reporting the SMTP error if any.
     */
    public function testMail(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate(['to' => ['required', 'email', 'max:255']], [], ['to' => 'destinatario']);

        try {
            Mail::purge();
            Mail::to($data['to'])->send(new TestMail);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', ['type' => 'error', 'message' => __('Invio non riuscito: :error', ['error' => $exception->getMessage()])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email di prova inviata a :email: controlla la casella (anche lo spam).', ['email' => $data['to']])]);

        return back();
    }

    /**
     * Tax regime, withholding tax, pension fund and stamp duty used by invoices.
     */
    public function updateFiscal(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'regime' => ['required', Rule::in(array_keys(FiscalCodes::REGIMES))],
            'withholding_enabled' => ['boolean'],
            'withholding_type' => ['required', Rule::in(array_keys(FiscalCodes::WITHHOLDING_TYPES))],
            'withholding_rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'withholding_reason' => ['required', Rule::in(array_keys(FiscalCodes::WITHHOLDING_REASONS))],
            'pension_enabled' => ['boolean'],
            'pension_type' => ['required', Rule::in(array_keys(FiscalCodes::PENSION_FUNDS))],
            'pension_rate' => ['required', 'numeric', 'gt:0', 'max:100'],
            'pension_vat_rate_id' => ['nullable', 'integer', Rule::exists('vat_rates', 'id'), Rule::requiredIf($request->boolean('pension_enabled'))],
            'pension_withholding' => ['boolean'],
            'stamp_duty_enabled' => ['boolean'],
            'stamp_duty_charge_customer' => ['boolean'],
            'invoice_notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'regime' => 'regime fiscale',
            'withholding_rate' => 'aliquota ritenuta',
            'pension_rate' => 'aliquota cassa',
            'pension_vat_rate_id' => 'IVA sulla cassa',
            'invoice_notes' => 'note in fattura',
        ]);

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["fiscal.{$key}" => $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni fiscali salvate.')]);

        return back();
    }

    public function updateQuotes(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'validity_days' => ['required', 'integer', 'between:1,365'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'email_subject' => ['required', 'string', 'max:255'],
            'email_body' => ['required', 'string', 'max:5000'],
        ], [], [
            'validity_days' => 'validità',
            'terms' => 'condizioni',
            'email_subject' => 'oggetto',
            'email_body' => 'testo',
        ]);

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["quotes.{$key}" => $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni dei preventivi salvate.')]);

        return back();
    }
}
