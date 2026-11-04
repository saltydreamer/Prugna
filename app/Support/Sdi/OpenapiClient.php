<?php

namespace App\Support\Sdi;

use App\Models\OpenapiCall;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Openapi "SDI Electronic Invoicing" (sdi.openapi.it, sandbox test.sdi.openapi.it).
 *
 * Authentication: a bearer token created with the account e-mail and API key (oauth.openapi.it, Basic auth)
 * for the SDI scopes, cached encrypted in the settings and renewed before it expires.
 * Every request is logged in openapi_calls with its price, for the cost summary.
 */
class OpenapiClient
{
    /** Openapi's recipient code: register it in "Fatture e Corrispettivi" to receive supplier invoices here. */
    public const RECIPIENT_CODE = 'JKKZDGR';

    /** Token lifetime requested (30 days); renewed when less than a day is left. */
    private const TOKEN_TTL = 2_592_000;

    private ?int $invoiceId = null;

    public function __construct(private Settings $settings) {}

    public function isConfigured(): bool
    {
        return (bool) $this->settings->get('sdi.enabled')
            && filled($this->settings->get('sdi.username'))
            && $this->settings->has('sdi.api_key');
    }

    public function isTest(): bool
    {
        return $this->settings->get('sdi.environment') !== 'production';
    }

    public function environment(): string
    {
        return $this->isTest() ? 'test' : 'production';
    }

    public function baseUrl(): string
    {
        return $this->isTest() ? 'https://test.sdi.openapi.it' : 'https://sdi.openapi.it';
    }

    public function oauthUrl(): string
    {
        return $this->isTest() ? 'https://test.oauth.openapi.it' : 'https://oauth.openapi.it';
    }

    /**
     * Calls made from now on are linked to this invoice in the cost log.
     */
    public function forInvoice(?int $invoiceId): static
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    // --- SDI endpoints ---------------------------------------------------------------------

    /**
     * Sends a FatturaPA XML: returns the data of the created invoice (uuid...).
     *
     * @return array<string, mixed>
     */
    public function sendInvoice(string $xml): array
    {
        $response = $this->call('POST', '/invoices', fn (PendingRequest $request) => $request
            ->withBody($xml, 'application/xml')
            ->post($this->baseUrl().'/invoices'));

        return (array) ($response->json('data') ?? $response->json());
    }

    /**
     * The XML of an invoice (sent or received) by uuid.
     */
    public function downloadXml(string $uuid): string
    {
        return $this->call('GET', '/invoices_download/'.$uuid, fn (PendingRequest $request) => $request
            ->accept('application/xml')
            ->get($this->baseUrl().'/invoices_download/'.rawurlencode($uuid)))->body();
    }

    /**
     * An invoice (sent or received) with its "marking" (status at Openapi) and notifications.
     *
     * @return array<string, mixed>
     */
    public function invoice(string $uuid): array
    {
        return $this->json('GET', '/invoices/'.$uuid, fn (PendingRequest $request) => $request->get($this->baseUrl().'/invoices/'.rawurlencode($uuid)));
    }

    /**
     * Notifications of a sent invoice.
     *
     * @return list<array<string, mixed>>
     */
    public function notifications(string $uuid): array
    {
        $data = $this->json('GET', '/invoices_notifications/'.$uuid, fn (PendingRequest $request) => $request->get($this->baseUrl().'/invoices_notifications/'.rawurlencode($uuid)));

        return array_values(array_filter(array_is_list($data) ? $data : [$data], 'is_array'));
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<int|string, mixed>
     */
    public function invoices(array $query = []): array
    {
        return $this->json('GET', '/invoices', fn (PendingRequest $request) => $request->get($this->baseUrl().'/invoices', $query));
    }

    /**
     * One page of invoices: type 0 sent, 1 received (max 30 per page).
     *
     * @param  array<string, scalar|null>  $query
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function invoicesPage(int $type, int $page = 1, array $query = []): array
    {
        $response = $this->call('GET', '/invoices', fn (PendingRequest $request) => $request->get($this->baseUrl().'/invoices', [
            'type' => $type,
            'page' => $page,
            'itemsPerPage' => 30,
            ...$query,
        ]));

        return [
            'data' => array_values(array_filter((array) $response->json('data'), 'is_array')),
            'has_more' => (bool) $response->json('hasMore'),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function businessRegistry(): array
    {
        return $this->json('GET', '/business_registry_configurations', fn (PendingRequest $request) => $request->get($this->baseUrl().'/business_registry_configurations'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createBusinessRegistry(array $data): array
    {
        return $this->json('POST', '/business_registry_configurations', fn (PendingRequest $request) => $request->post($this->baseUrl().'/business_registry_configurations', $data));
    }

    /**
     * @return array<int|string, mixed>
     */
    public function apiConfigurations(): array
    {
        return $this->json('GET', '/api_configurations', fn (PendingRequest $request) => $request->get($this->baseUrl().'/api_configurations'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createApiConfiguration(array $data): array
    {
        return $this->json('POST', '/api_configurations', fn (PendingRequest $request) => $request->post($this->baseUrl().'/api_configurations', $data));
    }

    public function deleteApiConfiguration(string $id): void
    {
        $this->call('DELETE', '/api_configurations/'.$id, fn (PendingRequest $request) => $request->delete($this->baseUrl().'/api_configurations/'.rawurlencode($id)));
    }

    /**
     * Sandbox only: simulates a "supplier-invoice" (JSON invoice payload) or a "customer-notification"
     * ({uuid, notification: RC|NS|MC|NE|DT|AT}); Openapi then calls our webhook.
     *
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    public function simulate(string $type, array $data): array
    {
        if (! $this->isTest()) {
            throw new OpenapiException(__('La simulazione è disponibile solo in ambiente di prova.'));
        }

        return $this->json('POST', '/simulate/'.$type, fn (PendingRequest $request) => $request->post($this->baseUrl().'/simulate/'.rawurlencode($type), $data));
    }

    /**
     * Usage counters kept by Openapi (oauth.openapi.it/counters/{period}/{date}), to compare with ours.
     *
     * @return array<int|string, mixed>
     */
    public function counters(string $period, string $date): array
    {
        $response = $this->oauth()->get($this->oauthUrl().'/counters/'.rawurlencode($period).'/'.rawurlencode($date));
        $this->throwUnlessOk($response);

        return (array) ($response->json('data') ?? $response->json());
    }

    // --- Token ---------------------------------------------------------------------------------

    public function token(bool $refresh = false): string
    {
        $expires = $this->settings->get('sdi.token_expires_at');
        $cached = $this->settings->get('sdi.token');

        if (! $refresh && $cached && $expires && Carbon::parse($expires)->subDay()->isFuture() && $this->settings->get('sdi.token_environment') === $this->environment()) {
            return $cached;
        }

        $scopes = $this->sdiScopes();

        try {
            $response = $this->oauth()->post($this->oauthUrl().'/token', ['scopes' => $scopes, 'ttl' => self::TOKEN_TTL]);
        } catch (ConnectionException) {
            throw new OpenapiException(__('Openapi non risponde: riprova tra qualche minuto.'));
        }

        $this->throwUnlessOk($response);
        $token = $response->json('token') ?? $response->json('data.token');

        if (! is_string($token) || $token === '') {
            throw new OpenapiException(__('Openapi non ha restituito il token di accesso.'), $response->status(), (array) $response->json());
        }

        $this->settings->set([
            'sdi.token' => $token,
            'sdi.token_expires_at' => now()->addSeconds(self::TOKEN_TTL)->toIso8601String(),
            'sdi.token_environment' => $this->environment(),
        ]);

        return $token;
    }

    /**
     * The SDI scopes enabled on the account ("POST:test.sdi.openapi.it/invoices"...).
     *
     * @return list<string>
     */
    public function sdiScopes(): array
    {
        try {
            $response = $this->oauth()->get($this->oauthUrl().'/scopes');
        } catch (ConnectionException) {
            throw new OpenapiException(__('Openapi non risponde: riprova tra qualche minuto.'));
        }

        $this->throwUnlessOk($response);

        $host = parse_url($this->baseUrl(), PHP_URL_HOST);
        $scopes = [];

        $data = (array) $response->json();

        array_walk_recursive($data, function ($value) use (&$scopes, $host) {
            if (is_string($value) && preg_match('#^[A-Z*]+:'.preg_quote((string) $host, '#').'/#', $value)) {
                $scopes[] = $value;
            }
        });

        if ($scopes === []) {
            throw new OpenapiException(__("Sul tuo account Openapi non risulta attivo il servizio «Fatturazione elettronica SDI» per l'ambiente :env.", ['env' => $this->isTest() ? 'di prova' : 'di produzione']));
        }

        return array_values(array_unique($scopes));
    }

    // --- Internals -----------------------------------------------------------------------------

    private function oauth(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->settings->get('sdi.username'), (string) $this->settings->get('sdi.api_key'))
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * @param  \Closure(PendingRequest): Response  $send
     * @return array<int|string, mixed>
     */
    private function json(string $method, string $path, \Closure $send): array
    {
        $response = $this->call($method, $path, $send);
        $json = $response->json();

        return is_array($json) && array_key_exists('data', $json) ? (array) $json['data'] : (array) $json;
    }

    /**
     * Sends an authenticated request, retrying once with a new token on 401, and logs it with its price.
     *
     * @param  \Closure(PendingRequest): Response  $send
     */
    private function call(string $method, string $path, \Closure $send): Response
    {
        if (! $this->isConfigured()) {
            throw new OpenapiException(__('La fatturazione elettronica non è configurata: completa Impostazioni → Fatturazione elettronica.'));
        }

        $endpoint = $method.' '.self::endpoint($path);
        $started = hrtime(true);
        $response = null;
        $error = null;

        try {
            $response = $send($this->request($this->token()));

            if ($response->status() === 401) {
                $response = $send($this->request($this->token(true)));
            }
        } catch (ConnectionException $exception) {
            $error = __('Openapi non risponde: riprova tra qualche minuto.');
        } catch (OpenapiException $exception) {
            $error = $exception->getMessage();
        }

        $ok = $response?->successful() ?? false;
        $this->log($endpoint, $response?->status(), $ok, (int) ((hrtime(true) - $started) / 1_000_000), $error ?? ($ok ? null : $this->errorMessage($response)));

        if ($error !== null) {
            throw new OpenapiException($error);
        }

        $this->throwUnlessOk($response);

        return $response;
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(60)->retry(2, 500, fn (Throwable $exception) => $exception instanceof ConnectionException, false);
    }

    private function throwUnlessOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new OpenapiException($this->errorMessage($response), $response->status(), is_array($response->json()) ? $response->json() : null);
    }

    private function errorMessage(?Response $response): string
    {
        if (! $response) {
            return __('Nessuna risposta da Openapi.');
        }

        $message = $response->json('message') ?? $response->json('error') ?? $response->json('errors.0.message');
        $message = is_string($message) && $message !== '' ? $message : mb_substr(trim(strip_tags($response->body())), 0, 300);

        return match ($response->status()) {
            401, 403 => __('Openapi ha rifiutato le credenziali (:status): controlla e-mail, API key e servizi attivi. :message', ['status' => $response->status(), 'message' => $message]),
            402 => __('Credito Openapi esaurito: ricarica il conto. :message', ['message' => $message]),
            default => __('Openapi ha risposto :status: :message', ['status' => $response->status(), 'message' => $message ?: '—']),
        };
    }

    private function log(string $endpoint, ?int $status, bool $ok, int $durationMs, ?string $error): void
    {
        OpenapiCall::create([
            'environment' => $this->environment(),
            'method' => strtok($endpoint, ' '),
            'endpoint' => $endpoint,
            'http_status' => $status,
            'success' => $ok,
            // Only successful calls are charged; the sandbox is free.
            'price' => $ok && ! $this->isTest() ? $this->priceOf($endpoint) : 0,
            'duration_ms' => $durationMs,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'invoice_id' => $this->invoiceId,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /**
     * Price of the next call to an endpoint: free while today's free calls last.
     */
    public function priceOf(string $endpoint): float
    {
        $entry = self::prices($this->settings)[$endpoint] ?? null;

        if (! $entry || $entry['price'] <= 0) {
            return 0.0;
        }

        if ($entry['free'] > 0) {
            $today = OpenapiCall::query()
                ->where('environment', 'production')
                ->where('endpoint', $endpoint)
                ->where('success', true)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            if ($today < $entry['free']) {
                return 0.0;
            }
        }

        return $entry['price'];
    }

    /**
     * The price list: endpoint => {price, free per day} (older numeric entries are prices without free calls).
     *
     * @return array<string, array{price: float, free: int}>
     */
    public static function prices(Settings $settings): array
    {
        $defaults = (array) Settings::DEFAULTS['sdi.prices'];

        return collect([...$defaults, ...(array) $settings->get('sdi.prices')])
            ->map(fn ($entry) => is_array($entry)
                ? ['price' => (float) ($entry['price'] ?? 0), 'free' => (int) ($entry['free'] ?? 0)]
                : ['price' => (float) $entry, 'free' => 0])
            ->all();
    }

    /**
     * "/invoices_download/9f1c..." → "/invoices_download/{id}".
     */
    public static function endpoint(string $path): string
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        return (string) preg_replace('#/(?=[^/]*\d)[A-Za-z0-9_.-]{6,}(?=/|$)#', '/{id}', $path);
    }
}
