<?php

namespace App\Actions\Sdi;

use App\Enums\SdiStatus;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\SdiTransmission;
use App\Models\User;
use App\Support\CustomerSnapshot;
use App\Support\FatturaPa\FatturaPaXml;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;

/**
 * Sends an issued invoice to the SDI through Openapi. Each attempt is an SdiTransmission with its own
 * ProgressivoInvio (the SDI refuses a file name twice); after a rejection the customer data is refreshed,
 * since a rejected invoice was never issued for tax purposes.
 */
class SendInvoiceToSdi
{
    public function __construct(private OpenapiClient $client, private FatturaPaXml $xml) {}

    public function handle(Invoice $invoice, ?User $user = null): SdiTransmission
    {
        abort_if($invoice->isDraft(), 409, __('Emetti la fattura prima di inviarla allo SDI.'));

        $current = $invoice->sdiTransmission;
        abort_if($current && ! $current->status->canResend(), 409, __('La fattura è già stata inviata allo SDI.'));

        if ($current?->status === SdiStatus::Rejected) {
            $invoice->forceFill(['customer' => CustomerSnapshot::from(Organization::withTrashed()->findOrFail($invoice->organization_id))])->save();
        }

        $transmission = SdiTransmission::create([
            'invoice_id' => $invoice->id,
            'environment' => $this->client->environment(),
            'status' => SdiStatus::Pending,
            'user_id' => $user?->id,
        ]);

        $progressive = FatturaPaXml::transmissionProgressive($transmission->id);
        $transmission->forceFill(['progressive' => $progressive, 'filename' => $this->xml->filename($invoice, $progressive)])->save();
        $invoice->forceFill(['sdi_status' => SdiStatus::Pending, 'sdi_transmission_id' => $transmission->id])->save();

        try {
            $data = $this->client->forInvoice($invoice->id)->sendInvoice($this->xml->make($invoice->fresh(), $progressive));
            $uuid = $data['uuid'] ?? $data['id'] ?? null;

            $transmission->forceFill([
                'uuid' => is_string($uuid) ? $uuid : null,
                'status' => SdiStatus::Sent,
                'sdi_file_id' => isset($data['sdi_file_id']) ? (string) $data['sdi_file_id'] : null,
                'sent_at' => now(),
            ])->save();
        } catch (OpenapiException $exception) {
            $transmission->forceFill(['status' => SdiStatus::Error, 'message' => $exception->getMessage()])->save();
        }

        $invoice->forceFill(['sdi_status' => $transmission->status])->save();

        return $transmission;
    }
}
