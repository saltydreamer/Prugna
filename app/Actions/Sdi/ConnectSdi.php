<?php

namespace App\Actions\Sdi;

use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * "Collega": checks the credentials (token for the SDI scopes), registers the company on Openapi
 * (no signature, no legal storage: the Agenzia delle Entrate service keeps them) and points the
 * callbacks of our events to the webhook, authenticated by a secret header. A new API configuration
 * replaces the previous one of the same fiscal_id.
 */
class ConnectSdi
{
    public const EVENTS = ['customer-invoice', 'customer-notification', 'supplier-invoice', 'invoice-status-quarantena', 'invoice-status-invoice-error'];

    public function __construct(private OpenapiClient $client, private Settings $settings) {}

    /**
     * @return array{fiscal_id: string, registry_created: bool, callbacks: int}
     */
    public function handle(): array
    {
        $company = $this->settings->group('company');
        $fiscalId = preg_replace('/^IT/i', '', (string) ($company['vat_number'] ?? ''));

        if (! $fiscalId) {
            throw new OpenapiException(__('Inserisci la partita IVA in Impostazioni → Azienda prima di collegare lo SDI.'));
        }

        $this->client->token(true);

        $registryCreated = false;

        if (! $this->matches($this->client->businessRegistry(), $fiscalId)) {
            $this->client->createBusinessRegistry([
                'fiscal_id' => $fiscalId,
                'name' => (string) ($company['name'] ?? config('app.name')),
                'email' => (string) ($company['email'] ?: auth()->user()?->email),
                'apply_signature' => false,
                'apply_legal_storage' => false,
            ]);
            $registryCreated = true;
        }

        $secret = (string) ($this->settings->get('sdi.webhook_secret') ?: Str::random(48));

        foreach ($this->rows($this->client->apiConfigurations()) as $configuration) {
            if ($this->sameFiscalId($configuration, $fiscalId) && isset($configuration['id'])) {
                $this->client->deleteApiConfiguration((string) $configuration['id']);
            }
        }

        $callbacks = array_map(fn (string $event) => [
            'event' => $event,
            'url' => route('webhooks.openapi.sdi', ['event' => $event]),
            'auth_header' => 'Bearer '.$secret,
        ], self::EVENTS);

        $this->client->createApiConfiguration(['fiscal_id' => $fiscalId, 'callbacks' => $callbacks]);

        $this->settings->set([
            'sdi.webhook_secret' => $secret,
            'sdi.fiscal_id' => $fiscalId,
            'sdi.connected_at' => now()->toIso8601String(),
            'sdi.connected_environment' => $this->client->environment(),
        ]);

        return ['fiscal_id' => $fiscalId, 'registry_created' => $registryCreated, 'callbacks' => count($callbacks)];
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function matches(array $data, string $fiscalId): bool
    {
        foreach ($this->rows($data) as $row) {
            if ($this->sameFiscalId($row, $fiscalId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data): array
    {
        if (isset($data['fiscal_id'])) {
            return [$data];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sameFiscalId(array $row, string $fiscalId): bool
    {
        return preg_replace('/^IT/i', '', (string) ($row['fiscal_id'] ?? '')) === $fiscalId;
    }
}
