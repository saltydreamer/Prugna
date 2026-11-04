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
#[Signature('sdi:diagnose {--limit=10 : Rows per section} {--probe : Also list the keys of GET /invoices (shape only)}')]
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
                foreach ((array) ($row['callbacks'] ?? $row['callback'] ?? []) as $callback) {
                    if (is_array($callback)) {
                        $this->line(sprintf('Callback: %s → %s', $callback['event'] ?? '?', $callback['url'] ?? '?'));
                    }
                }
            }

            if ($this->option('probe')) {
                $list = $client->invoices(['per_page' => 1]);
                $first = is_array($list[0] ?? null) ? $list[0] : (is_array(reset($list)) ? reset($list) : []);
                $this->line('GET /invoices keys: '.implode(',', array_keys($list)).' · first item keys: '.implode(',', array_keys((array) $first)));
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
