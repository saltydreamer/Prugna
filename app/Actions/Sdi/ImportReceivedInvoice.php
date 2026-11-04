<?php

namespace App\Actions\Sdi;

use App\Models\ReceivedInvoice;
use App\Support\Ai\AiException;
use App\Support\FatturaPa\FatturaPaReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Stores a supplier electronic invoice (XML or .p7m) in the "Fatture ricevute" inbox, once:
 * the same file (or the same SDI uuid) is never imported twice.
 */
class ImportReceivedInvoice
{
    public function __construct(private FatturaPaReader $reader) {}

    /**
     * @return array{invoice: ReceivedInvoice, created: bool}
     *
     * @throws AiException when the file is not a readable FatturaPA
     */
    public function handle(string $contents, string $source, ?string $uuid = null, ?string $filename = null): array
    {
        $hash = hash('sha256', $contents);
        $existing = ReceivedInvoice::query()->where('hash', $hash)->when($uuid, fn ($query) => $query->orWhere('uuid', $uuid))->first();

        if ($existing) {
            return ['invoice' => $existing, 'created' => false];
        }

        $data = $this->reader->read($contents);
        $extension = str_ends_with(strtolower((string) $filename), '.p7m') || ! str_contains(substr($contents, 0, 200), '<') ? 'xml.p7m' : 'xml';
        $path = 'received-invoices/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk(ReceivedInvoice::DISK)->put($path, $contents);

        $invoice = ReceivedInvoice::create([
            'source' => $source,
            'uuid' => $uuid,
            'hash' => $hash,
            'supplier_name' => $data['supplier']['name'] ?? null,
            'supplier_vat' => $data['supplier']['vat_number'] ?? $data['supplier']['tax_code'] ?? null,
            'document_type' => $data['document_type'] === 'credit_note' ? 'TD04' : 'TD01',
            'number' => $data['number'] ?? null,
            'document_date' => $data['date'] ?? null,
            'total' => $data['total'] ?? null,
            'file_path' => $path,
            'filename' => $filename ? Str::limit(basename($filename), 250, '') : null,
            'status' => ReceivedInvoice::STATUS_NEW,
            'received_at' => now(),
        ]);

        return ['invoice' => $invoice, 'created' => true];
    }

    /**
     * Imports every invoice of a ZIP downloaded from "Fatture e Corrispettivi" (metadata files are skipped).
     *
     * @return array{created: int, duplicates: int, skipped: int}
     */
    public function zip(string $path): array
    {
        $counts = ['created' => 0, 'duplicates' => 0, 'skipped' => 0];
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new AiException(__("L'archivio ZIP non è leggibile."));
        }

        for ($index = 0; $index < min($zip->numFiles, 2000); $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! preg_match('/\.(xml|p7m)$/i', $name) || str_contains(strtoupper(basename($name)), '_MT_')) {
                continue;
            }

            $contents = (string) $zip->getFromIndex($index);

            try {
                $result = $this->handle($contents, ReceivedInvoice::SOURCE_IMPORT, null, $name);
                $counts[$result['created'] ? 'created' : 'duplicates']++;
            } catch (AiException) {
                $counts['skipped']++;
            }
        }

        $zip->close();

        return $counts;
    }
}
