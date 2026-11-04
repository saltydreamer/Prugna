<?php

namespace App\Console\Commands;

use App\Models\OpenapiCall;
use App\Models\SdiEvent;
use App\Models\SdiTransmission;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Read-only check of the Openapi SDI connection: configuration, token and scopes, registry and callbacks,
 * latest transmissions, events and calls. Never prints secrets, tokens or invoice contents
 * (only the structure of the responses, i.e. their keys).
 */
#[Signature('sdi:diagnose {--limit=10 : Rows per section}')]
#[Description('Diagnose the Openapi SDI integration without printing secrets or contents')]
class SdiDiagnoseCommand extends Command
{
    public function handle(Settings $settings, OpenapiClient $client): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->line(sprintf(
            'Enabled: %s · environment: %s · user: %s · API key: %s · connected: %s (%s)',
            $settings->get('sdi.enabled') ? 'yes' : 'no',
            $client->environment(),
            $this->mask((string) $settings->get('sdi.username')),
            $settings->has('sdi.api_key') ? 'set' : 'missing',
            $settings->get('sdi.connected_at') ?? 'never',
            $settings->get('sdi.connected_environment') ?? '-',
        ));
        $this->line('Webhook: '.route('webhooks.openapi.sdi').' · secret: '.($settings->has('sdi.webhook_secret') ? 'set' : 'missing'));

        if ($client->isConfigured()) {
            $this->remote($client);
        }

        $this->newLine();
        $this->line('Latest transmissions:');
        foreach (SdiTransmission::query()->with('invoice:id,number')->latest('id')->limit($limit)->get() as $transmission) {
            $this->line(sprintf(
                '  #%d %s · invoice %s · %s · %s · uuid %s · errors %d%s',
                $transmission->id,
                $transmission->created_at->format('Y-m-d H:i'),
                $transmission->invoice?->number ?? '-',
                $transmission->environment,
                $transmission->status->value,
                $transmission->uuid ? substr($transmission->uuid, 0, 8).'…' : '-',
                count($transmission->errors ?? []),
                $transmission->message ? ' · '.mb_strimwidth($transmission->message, 0, 160, '…') : '',
            ));
        }

        $this->newLine();
        $this->line('Latest callbacks:');
        foreach (SdiEvent::query()->latest('id')->limit($limit)->get() as $event) {
            $this->line(sprintf(
                '  #%d %s · %s %s · %s · keys: %s%s',
                $event->id,
                $event->created_at->format('Y-m-d H:i'),
                $event->event,
                $event->type ?? '',
                $event->result,
                implode(',', $this->keys($event->payload)),
                $event->error ? ' · '.mb_strimwidth($event->error, 0, 160, '…') : '',
            ));
        }

        $this->newLine();
        $this->line('Latest calls:');
        foreach (OpenapiCall::query()->latest('id')->limit($limit)->get() as $call) {
            $this->line(sprintf(
                '  %s · %s · %s · %s · %s ms · € %s%s',
                $call->created_at->format('Y-m-d H:i'),
                $call->environment,
                $call->endpoint,
                $call->http_status ?? '-',
                $call->duration_ms ?? '-',
                $call->price,
                $call->error ? ' · '.mb_strimwidth($call->error, 0, 200, '…') : '',
            ));
        }

        return self::SUCCESS;
    }

    private function remote(OpenapiClient $client): void
    {
        try {
            $scopes = $client->sdiScopes();
            $this->line('Scopes ('.count($scopes).'): '.implode(' ', array_map(fn (string $scope) => preg_replace('#^([A-Z*]+):[^/]+#', '$1:', $scope), $scopes)));
            $client->token();
            $this->line('Token: ok');

            foreach ($this->rows($client->businessRegistry()) as $row) {
                $this->line(sprintf('Registry: fiscal_id %s · signature %s · legal storage %s · keys %s', $row['fiscal_id'] ?? '?', json_encode($row['apply_signature'] ?? null), json_encode($row['apply_legal_storage'] ?? null), implode(',', array_keys($row))));
            }

            foreach ($this->rows($client->apiConfigurations()) as $row) {
                $callbacks = $row['callbacks'] ?? $row['callback'] ?? [];
                $callbacks = is_array($callbacks) && ! array_is_list($callbacks) ? [$callbacks] : (array) $callbacks;

                foreach ($callbacks as $callback) {
                    if (is_array($callback)) {
                        $this->line(sprintf('Callback: %s → %s · fiscal_id %s · keys %s', $callback['event'] ?? '?', $callback['url'] ?? '?', $row['fiscal_id'] ?? '?', implode(',', array_keys($row))));
                    }
                }
            }

            $invoices = $client->invoices();
            $this->line('GET /invoices: '.count($invoices).' items · first item keys: '.implode(',', array_keys((array) (reset($invoices) ?: []))));

            // Status fields of our latest transmissions as Openapi sees them (no invoice contents).
            foreach (SdiTransmission::query()->whereNotNull('uuid')->latest('id')->limit(3)->get() as $transmission) {
                $item = collect($invoices)->first(fn ($row) => is_array($row) && ($row['uuid'] ?? null) === $transmission->uuid);
                $notifications = (array) ($item['notifications'] ?? []);

                $this->line(sprintf(
                    'Openapi view of #%d: %s · marking %s · notice %s · sdi_file_id %s · sdi_file_name %s · notifications %d (%s) · retry %s',
                    $transmission->id,
                    $item ? 'found' : 'not in list',
                    json_encode($item['marking'] ?? null),
                    mb_strimwidth((string) json_encode($item['notice'] ?? null), 0, 160, '…'),
                    json_encode($item['sdi_file_id'] ?? null),
                    json_encode($item['sdi_file_name'] ?? null),
                    count($notifications),
                    implode(' ', array_map(fn ($notification) => is_array($notification) ? ($notification['type'] ?? '?').'['.implode(',', array_keys($notification)).']' : '?', array_slice($notifications, 0, 5))),
                    mb_strimwidth((string) json_encode($item['retry_information'] ?? null), 0, 120, '…'),
                ));

                $polled = $client->notifications($transmission->uuid);
                $this->line('  GET /invoices_notifications: '.count($polled).' · '.implode(' ', array_map(fn ($notification) => ($notification['type'] ?? '?').'['.implode(',', array_keys($notification)).']', array_slice($polled, 0, 5))));
            }
        } catch (OpenapiException $exception) {
            $this->error('Openapi: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function rows(array $data): array
    {
        return isset($data['fiscal_id']) ? [$data] : array_values(array_filter($data, 'is_array'));
    }

    /**
     * Keys of a payload, two levels deep ("data.notification"), never values.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function keys(array $payload): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            $keys[] = (string) $key;

            if (is_array($value) && ! array_is_list($value)) {
                foreach (array_keys($value) as $child) {
                    $keys[] = $key.'.'.$child;
                }
            }
        }

        return array_slice($keys, 0, 20);
    }

    private function mask(string $email): string
    {
        return $email === '' ? '-' : preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email);
    }
}
