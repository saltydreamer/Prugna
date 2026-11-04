<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Sdi\ConnectSdi;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\OpenapiCall;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fatturazione elettronica: Openapi credentials, connection, price list and cost summary.
 */
class ElectronicInvoicingController extends Controller
{
    /** Endpoints shown in the price list (the ones the app calls). */
    public const PRICED_ENDPOINTS = [
        'POST /invoices' => 'Invio di una fattura allo SDI',
        'GET /invoices_download/{id}' => 'Download dell\'XML di una fattura ricevuta',
        'GET /invoices_notifications/{id}' => 'Lettura delle notifiche di una fattura',
        'GET /invoices' => 'Elenco fatture',
        'POST /business_registry_configurations' => 'Registrazione azienda',
        'GET /business_registry_configurations' => 'Verifica registrazione azienda',
        'POST /api_configurations' => 'Configurazione webhook',
        'GET /api_configurations' => 'Verifica webhook',
        'DELETE /api_configurations/{id}' => 'Rimozione webhook',
        'POST /simulate-supplier-invoice' => 'Fattura fornitore di prova (solo sandbox)',
    ];

    public function __construct(private Settings $settings) {}

    public function index(): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        return Inertia::render('einvoicing/Settings', [
            'settings' => [
                'enabled' => (bool) $this->settings->get('sdi.enabled'),
                'environment' => $this->settings->get('sdi.environment'),
                'username' => $this->settings->get('sdi.username'),
                'has_api_key' => $this->settings->has('sdi.api_key'),
                'auto_send' => (bool) $this->settings->get('sdi.auto_send'),
                'connected_at' => $this->settings->get('sdi.connected_at'),
                'connected_environment' => $this->settings->get('sdi.connected_environment'),
                'fiscal_id' => $this->settings->get('sdi.fiscal_id'),
            ],
            'recipientCode' => OpenapiClient::RECIPIENT_CODE,
            'webhookUrl' => route('webhooks.openapi.sdi'),
            'prices' => collect(self::PRICED_ENDPOINTS)->map(fn (string $label, string $endpoint) => [
                'endpoint' => $endpoint,
                'label' => $label,
                'price' => (float) (((array) $this->settings->get('sdi.prices'))[$endpoint] ?? 0),
            ])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'enabled' => ['boolean'],
            'environment' => ['required', Rule::in(['test', 'production'])],
            'username' => ['nullable', 'required_if:enabled,true', 'email', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'auto_send' => ['boolean'],
        ], [], ['username' => 'e-mail dell\'account Openapi', 'api_key' => 'API key', 'environment' => 'ambiente']);

        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        } else {
            // New credentials: the cached token is no longer valid.
            $this->settings->set(['sdi.token' => null, 'sdi.token_expires_at' => null]);
        }

        if ($request->boolean('enabled') && ! isset($data['api_key']) && ! $this->settings->has('sdi.api_key')) {
            return back()->withErrors(['api_key' => __("Inserisci l'API key di Openapi.")]);
        }

        if ($data['environment'] !== $this->settings->get('sdi.environment')) {
            $this->settings->set(['sdi.token' => null, 'sdi.token_expires_at' => null, 'sdi.connected_at' => null]);
        }

        $this->settings->set(collect($data)->mapWithKeys(fn ($value, $key) => ["sdi.{$key}" => $value])->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Impostazioni della fatturazione elettronica salvate.')]);

        return back();
    }

    public function connect(ConnectSdi $connect): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        try {
            $result = $connect->handle();
        } catch (OpenapiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collegamento con Openapi completato per la P.IVA :id.', ['id' => $result['fiscal_id']])]);

        return back();
    }

    public function updatePrices(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $prices = collect($data['prices'])->only(array_keys(self::PRICED_ENDPOINTS))->map(fn ($price) => round((float) $price, 4))->all();

        $this->settings->set(['sdi.prices' => $prices]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Listino aggiornato: vale per le prossime chiamate.')]);

        return back();
    }

    /**
     * Cost summary of the Openapi calls: per month, per endpoint, with daily totals.
     */
    public function costs(Request $request): Response
    {
        Gate::authorize(Permission::SettingsManage->value);

        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $calls = OpenapiCall::query()->whereBetween('created_at', [$from, $to]);

        $byEndpoint = (clone $calls)
            ->select('endpoint', 'environment', DB::raw('count(*) as calls'), DB::raw('sum(case when success = 1 then 1 else 0 end) as ok'), DB::raw('sum(price) as total'))
            ->groupBy('endpoint', 'environment')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'endpoint' => $row->endpoint,
                'label' => self::PRICED_ENDPOINTS[$row->endpoint] ?? null,
                'environment' => $row->environment,
                'calls' => (int) $row->calls,
                'failed' => (int) $row->calls - (int) $row->ok,
                'total' => round((float) $row->total, 4),
            ]);

        $daily = (clone $calls)
            ->select(DB::raw('date(created_at) as day'), DB::raw('count(*) as calls'), DB::raw('sum(price) as total'))
            ->groupBy(DB::raw('date(created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => (string) $row->day, 'calls' => (int) $row->calls, 'total' => round((float) $row->total, 4)]);

        $months = OpenapiCall::query()
            ->select(DB::raw('min(created_at) as first'))
            ->value('first');

        return Inertia::render('einvoicing/Costs', [
            'month' => $month,
            'firstMonth' => $months ? Carbon::parse($months)->format('Y-m') : $month,
            'summary' => [
                'month_total' => round((float) (clone $calls)->sum('price'), 4),
                'month_calls' => (clone $calls)->count(),
                'month_failed' => (clone $calls)->where('success', false)->count(),
                'year_total' => round((float) OpenapiCall::query()->whereBetween('created_at', [$from->copy()->startOfYear(), $from->copy()->endOfYear()])->sum('price'), 4),
                'invoices_sent' => (clone $calls)->where('endpoint', 'POST /invoices')->where('success', true)->where('environment', 'production')->count(),
            ],
            'byEndpoint' => $byEndpoint,
            'daily' => $daily,
            'recent' => OpenapiCall::query()->latest('id')->limit(30)->get()->map(fn (OpenapiCall $call) => [
                'id' => $call->id,
                'created_at' => $call->created_at->toIso8601String(),
                'endpoint' => $call->endpoint,
                'environment' => $call->environment,
                'http_status' => $call->http_status,
                'success' => $call->success,
                'price' => (float) $call->price,
                'duration_ms' => $call->duration_ms,
                'error' => $call->error,
                'invoice_id' => $call->invoice_id,
            ]),
        ]);
    }

    /**
     * Usage counters as Openapi records them, to compare with our log.
     */
    public function counters(Request $request, OpenapiClient $client): JsonResponse
    {
        Gate::authorize(Permission::SettingsManage->value);

        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');

        try {
            return response()->json(['data' => $client->counters('monthly', $month)]);
        } catch (OpenapiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
