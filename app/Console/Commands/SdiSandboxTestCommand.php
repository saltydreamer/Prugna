<?php

namespace App\Console\Commands;

use App\Actions\Sdi\ConnectSdi;
use App\Actions\Sdi\SimulateSupplierInvoice;
use App\Models\ReceivedInvoice;
use App\Models\SdiTransmission;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sandbox only: refreshes the callbacks, simulates an SDI outcome for the latest test transmission and
 * a supplier invoice, then waits for the callbacks to arrive. Nothing leaves the Openapi sandbox.
 */
#[Signature('sdi:sandbox-test {--notification=RC : Outcome to simulate (RC, MC, NS, DT, AT)} {--wait=40 : Seconds to wait for the callbacks}')]
#[Description('Run an end-to-end test of the SDI integration in the Openapi sandbox')]
class SdiSandboxTestCommand extends Command
{
    public function handle(OpenapiClient $client, ConnectSdi $connect, SimulateSupplierInvoice $simulate): int
    {
        if (! $client->isConfigured() || ! $client->isTest()) {
            $this->error('Solo con la fatturazione elettronica attiva in ambiente di prova.');

            return self::FAILURE;
        }

        try {
            $result = $connect->handle();
            $this->line("Callbacks refreshed: {$result['callbacks']} (fiscal_id {$result['fiscal_id']})");

            $transmission = SdiTransmission::query()->where('environment', 'test')->whereNotNull('uuid')->latest('id')->first();

            if ($transmission) {
                $client->forInvoice($transmission->invoice_id)->simulate('customer-notification', ['uuid' => $transmission->uuid, 'notification' => strtoupper((string) $this->option('notification'))]);
                $this->line("Simulated {$this->option('notification')} for transmission #{$transmission->id} ({$transmission->status->value})");
            } else {
                $this->warn('No test transmission to simulate an outcome for.');
            }

            $received = ReceivedInvoice::query()->count();
            $simulate->handle();
            $this->line('Simulated a supplier invoice');
        } catch (OpenapiException $exception) {
            $this->error('Openapi: '.$exception->getMessage());

            return self::FAILURE;
        }

        $deadline = now()->addSeconds((int) $this->option('wait'));

        do {
            sleep(5);
            $status = $transmission?->refresh()->status->value;
            $newInvoices = ReceivedInvoice::query()->count() - $received;
        } while (now()->lt($deadline) && (($transmission && $status === 'sent') || $newInvoices === 0));

        $this->line('Transmission status now: '.($status ?? '-'));
        $this->line("New received invoices: {$newInvoices}");

        return self::SUCCESS;
    }
}
