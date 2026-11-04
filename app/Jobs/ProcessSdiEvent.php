<?php

namespace App\Jobs;

use App\Actions\Sdi\ImportReceivedInvoice;
use App\Enums\SdiStatus;
use App\Models\ReceivedInvoice;
use App\Models\SdiEvent;
use App\Models\SdiTransmission;
use App\Notifications\SdiOutcomeNotification;
use App\Support\Ai\AiException;
use App\Support\Sdi\OpenapiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Applies a callback received from Openapi:
 * - customer-invoice: the intermediary forwarded our invoice to the SDI;
 * - customer-notification: SDI outcome (RC delivered, MC not delivered, NS rejected, NE accepted/refused, DT);
 * - supplier-invoice: a supplier invoice addressed to us, stored in "Fatture ricevute".
 */
class ProcessSdiEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $eventId) {}

    public function handle(OpenapiClient $client, ImportReceivedInvoice $import): void
    {
        $event = SdiEvent::query()->find($this->eventId);

        if (! $event || $event->result === 'done') {
            return;
        }

        $data = self::unwrap($event->payload);

        try {
            match ($event->event) {
                'customer-invoice' => $this->customerInvoice($event, $data),
                'customer-notification' => $this->notification($event, $data),
                'supplier-invoice' => $this->supplierInvoice($event, $data, $client, $import),
                'invoice-status-quarantena', 'invoice-status-invoice-error' => $this->marking($event, $event->payload),
                default => $event->forceFill(['result' => 'ignored'])->save(),
            };
        } catch (AiException $exception) {
            $event->forceFill(['result' => 'failed', 'error' => $exception->getMessage()])->save();
        } catch (Throwable $exception) {
            $event->forceFill(['error' => mb_substr($exception->getMessage(), 0, 1000)])->save();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function customerInvoice(SdiEvent $event, array $data): void
    {
        $uuid = (string) (Arr::get($data, 'invoice.uuid') ?? Arr::get($data, 'uuid'));
        $transmission = $this->transmission($event, $uuid);

        if ($transmission && in_array($transmission->status, [SdiStatus::Pending, SdiStatus::Error], true)) {
            $transmission->forceFill(['status' => SdiStatus::Sent, 'sent_at' => $transmission->sent_at ?? now()]);
        }

        // Openapi transmits as intermediary, with its own file name and SDI identifier.
        if ($transmission && ($fileId = Arr::get($data, 'invoice.file_id') ?? Arr::get($data, 'invoice.sdi_file_id'))) {
            $transmission->sdi_file_id = (string) $fileId;
        }

        if ($transmission && is_string($filename = Arr::get($data, 'invoice.filename')) && $filename !== '') {
            $transmission->filename = mb_substr($filename, 0, 64);
        }

        $transmission?->save();
        $this->sync($transmission);
        $event->forceFill(['result' => $transmission ? 'done' : 'unmatched'])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notification(SdiEvent $event, array $data): void
    {
        $notification = (array) (Arr::get($data, 'notification') ?? $data);
        $uuid = (string) ($notification['invoice_uuid'] ?? Arr::get($data, 'invoice.uuid') ?? '');
        $type = strtoupper((string) ($notification['type'] ?? ''));
        $outcome = Arr::get($notification, 'message.esito_committente.esito');
        $status = SdiStatus::fromNotification($type, is_string($outcome) ? $outcome : null);

        $event->type = $type ?: null;
        $transmission = $this->transmission($event, $uuid);

        if ($transmission && $status) {
            $errors = self::errors(Arr::get($notification, 'message.lista_errori'));
            $transmission->forceFill([
                'status' => $status,
                'errors' => $errors ?: $transmission->errors,
                'outcome_at' => isset($notification['created_at']) ? Carbon::parse($notification['created_at']) : now(),
                'message' => $status === SdiStatus::Refused ? (string) (Arr::get($notification, 'message.esito_committente.descrizione') ?? '') ?: null : $transmission->message,
            ])->save();

            $this->sync($transmission);

            if (in_array($status, [SdiStatus::Rejected, SdiStatus::Refused, SdiStatus::NotDelivered], true)) {
                $transmission->invoice?->owner?->notify(new SdiOutcomeNotification($transmission));
            }
        }

        $event->forceFill(['result' => $transmission ? 'done' : 'unmatched'])->save();
    }

    /**
     * Openapi could not hand the invoice to the SDI: "quarantena" (it retries) or "invoice-error" (it gave up).
     *
     * @param  array<string, mixed>  $data
     */
    private function marking(SdiEvent $event, array $data): void
    {
        $data = isset($data['uuid']) ? $data : self::unwrap($data);
        $transmission = $this->transmission($event, (string) ($data['uuid'] ?? ''));
        $status = SdiStatus::fromMarking($data['marking'] ?? null);
        $event->type = isset($data['marking']) ? mb_substr((string) $data['marking'], 0, 16) : null;

        if ($transmission && $status && ! in_array($transmission->status, [SdiStatus::Delivered, SdiStatus::NotDelivered, SdiStatus::Rejected, SdiStatus::Accepted, SdiStatus::Refused, SdiStatus::Expired], true)) {
            $notice = is_string($data['notice'] ?? null) ? $data['notice'] : null;
            $transmission->forceFill([
                'status' => $status,
                'message' => $status === SdiStatus::Error
                    ? __('Openapi non è riuscito a consegnare la fattura allo SDI: :notice', ['notice' => $notice ?? '—'])
                    : __('In quarantena presso Openapi, che riprova l\'invio: :notice', ['notice' => $notice ?? '—']),
            ])->save();
            $this->sync($transmission);
        }

        $event->forceFill(['result' => $transmission ? 'done' : 'unmatched'])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function supplierInvoice(SdiEvent $event, array $data, OpenapiClient $client, ImportReceivedInvoice $import): void
    {
        $uuid = Arr::get($data, 'invoice.uuid') ?? Arr::get($data, 'uuid');
        $uuid = is_string($uuid) ? $uuid : null;
        $event->uuid = $uuid;

        // Already received (callback repeated, or recovered from the list): no paid download.
        if ($uuid && ($existing = ReceivedInvoice::query()->where('uuid', $uuid)->value('id'))) {
            $event->forceFill(['received_invoice_id' => $existing, 'result' => 'done'])->save();

            return;
        }

        // The callback may carry the XML; otherwise it is downloaded (a cheap paid call).
        $xml = Arr::get($data, 'invoice.xml') ?? Arr::get($data, 'xml');
        $xml = is_string($xml) && str_contains($xml, 'FatturaElettronica') ? $xml : ($uuid ? $client->downloadXml($uuid) : null);

        if (! $xml) {
            $event->forceFill(['result' => 'failed', 'error' => 'Fattura senza uuid né XML'])->save();

            return;
        }

        $result = $import->handle($xml, 'sdi', $uuid, Arr::get($data, 'invoice.filename') ?? ($uuid ? "{$uuid}.xml" : null));
        $event->forceFill(['received_invoice_id' => $result['invoice']->id, 'result' => 'done'])->save();
    }

    private function transmission(SdiEvent $event, string $uuid): ?SdiTransmission
    {
        $event->uuid = $uuid ?: null;
        $transmission = $uuid !== '' ? SdiTransmission::query()->where('uuid', $uuid)->latest('id')->first() : null;
        $event->sdi_transmission_id = $transmission?->id;

        return $transmission;
    }

    /**
     * The invoice shows the status of its latest transmission.
     */
    private function sync(?SdiTransmission $transmission): void
    {
        $invoice = $transmission?->invoice;

        if ($invoice && $invoice->sdi_transmission_id === $transmission->id) {
            $invoice->forceFill(['sdi_status' => $transmission->status])->save();
        }
    }

    /**
     * Callbacks may wrap the payload in "data" once or twice.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function unwrap(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return is_array($data['data'] ?? null) ? $data['data'] : $data;
    }

    /**
     * SDI error list ({Errore: [{Codice, Descrizione, Suggerimento}]} in any casing) as readable lines.
     *
     * @return list<string>
     */
    public static function errors(mixed $list): array
    {
        if (! is_array($list)) {
            return is_string($list) && $list !== '' ? [$list] : [];
        }

        $errors = [];
        $walk = function (array $node) use (&$walk, &$errors) {
            $lower = array_change_key_case($node);

            if (isset($lower['descrizione']) || isset($lower['codice'])) {
                $errors[] = trim(implode(' – ', array_filter([
                    $lower['codice'] ?? null,
                    $lower['descrizione'] ?? null,
                    isset($lower['suggerimento']) ? __('Suggerimento: :text', ['text' => $lower['suggerimento']]) : null,
                ], fn ($value) => is_string($value) && $value !== '')));

                return;
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        $walk($list);

        return array_values(array_filter($errors));
    }
}
