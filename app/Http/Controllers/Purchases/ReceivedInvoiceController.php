<?php

namespace App\Http\Controllers\Purchases;

use App\Actions\Sdi\ImportReceivedInvoice;
use App\Actions\Sdi\SimulateSupplierInvoice;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSdiEvent;
use App\Models\AiExtraction;
use App\Models\Expense;
use App\Models\ReceivedInvoice;
use App\Models\SdiEvent;
use App\Support\Ai\AiException;
use App\Support\Ai\DocumentReader;
use App\Support\FatturaPa\FatturaPaReader;
use App\Support\Sdi\OpenapiClient;
use App\Support\Sdi\OpenapiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fatture ricevute: supplier electronic invoices received from the SDI or imported from
 * "Fatture e Corrispettivi", to record as expenses.
 */
class ReceivedInvoiceController extends Controller
{
    public function index(Request $request, OpenapiClient $sdi): Response
    {
        $this->authorize('viewAny', Expense::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', ReceivedInvoice::STATUS_NEW, ReceivedInvoice::STATUS_RECORDED, ReceivedInvoice::STATUS_IGNORED])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ]);

        $status = $filters['status'] ?? ReceivedInvoice::STATUS_NEW;

        $invoices = ReceivedInvoice::query()
            ->with('expense:id,number,document_date')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return Inertia::render('purchases/received/Index', [
            'invoices' => $invoices->through(fn (ReceivedInvoice $invoice) => [
                'id' => $invoice->id,
                'source' => $invoice->source,
                'supplier_name' => $invoice->supplier_name,
                'supplier_vat' => $invoice->supplier_vat,
                'document_type' => $invoice->document_type,
                'is_credit_note' => $invoice->isCreditNote(),
                'number' => $invoice->number,
                'document_date' => $invoice->document_date?->toDateString(),
                'total' => (float) $invoice->total,
                'status' => $invoice->status,
                'expense_id' => $invoice->expense_id,
                'received_at' => $invoice->received_at?->toIso8601String(),
            ]),
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $status, 'per_page' => (int) ($filters['per_page'] ?? 25)],
            'counts' => [
                'new' => ReceivedInvoice::query()->where('status', ReceivedInvoice::STATUS_NEW)->count(),
            ],
            'sdi' => [
                'enabled' => $sdi->isConfigured(),
                'test' => $sdi->isTest(),
                'recipient_code' => OpenapiClient::RECIPIENT_CODE,
            ],
        ]);
    }

    /**
     * XML / .p7m files or ZIP archives downloaded from "Fatture e Corrispettivi".
     */
    public function import(Request $request, ImportReceivedInvoice $import): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file', 'max:51200', 'extensions:xml,p7m,zip'],
        ], [], ['files' => 'file', 'files.*' => 'file']);

        $counts = ['created' => 0, 'duplicates' => 0, 'skipped' => 0];

        foreach ($request->file('files') as $file) {
            if (strtolower($file->getClientOriginalExtension()) === 'zip') {
                try {
                    foreach ($import->zip($file->getRealPath()) as $key => $count) {
                        $counts[$key] += $count;
                    }
                } catch (AiException) {
                    $counts['skipped']++;
                }

                continue;
            }

            try {
                $result = $import->handle((string) $file->get(), ReceivedInvoice::SOURCE_IMPORT, null, $file->getClientOriginalName());
                $counts[$result['created'] ? 'created' : 'duplicates']++;
            } catch (AiException) {
                $counts['skipped']++;
            }
        }

        Inertia::flash('toast', [
            'type' => $counts['created'] ? 'success' : 'warning',
            'message' => __('Importate :created fatture; già presenti :duplicates; file non riconosciuti :skipped.', $counts),
        ]);

        return back();
    }

    /**
     * Opens the expense form prefilled from the XML (nothing is saved until confirmed).
     */
    public function record(ReceivedInvoice $receivedInvoice, FatturaPaReader $reader): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        if ($receivedInvoice->expense_id) {
            return to_route('purchases.expenses.show', $receivedInvoice->expense_id);
        }

        $contents = (string) Storage::disk(ReceivedInvoice::DISK)->get($receivedInvoice->file_path);
        $extension = str_ends_with($receivedInvoice->file_path, '.p7m') ? 'xml.p7m' : 'xml';
        $path = 'documents/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk(DocumentReader::DISK)->put($path, $contents);

        $extraction = AiExtraction::create([
            'user_id' => request()->user()->id,
            'kind' => AiExtraction::KIND_EXPENSE,
            'source' => 'xml',
            'status' => 'succeeded',
            'original_name' => $receivedInvoice->filename ?: "fattura-{$receivedInvoice->id}.xml",
            'mime_type' => 'application/xml',
            'file_size' => strlen($contents),
            'file_path' => $path,
            'result' => $reader->read($contents),
            'cost' => 0,
            'duration_ms' => 0,
        ]);

        $receivedInvoice->forceFill(['ai_extraction_id' => $extraction->id])->save();

        return to_route('purchases.expenses.create', ['extraction' => $extraction->id]);
    }

    /**
     * Recovers from Openapi the received invoices not yet here (e.g. a callback that never arrived).
     */
    public function sync(OpenapiClient $client): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $created = 0;

        try {
            for ($page = 1; $page <= 10; $page++) {
                $result = $client->invoicesPage(1, $page);

                foreach ($result['data'] as $item) {
                    $uuid = $item['uuid'] ?? null;

                    if (! is_string($uuid) || ReceivedInvoice::query()->where('uuid', $uuid)->exists()) {
                        continue;
                    }

                    $event = SdiEvent::create([
                        'event' => 'supplier-invoice',
                        'payload' => ['event' => 'supplier-invoice', 'source' => 'sync', 'data' => ['invoice' => ['uuid' => $uuid, 'filename' => $item['sdi_file_name'] ?? null]]],
                    ]);
                    ProcessSdiEvent::dispatchSync($event->id);
                    $created += (int) ($event->refresh()->received_invoice_id !== null);
                }

                if (! $result['has_more']) {
                    break;
                }
            }
        } catch (OpenapiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $created
            ? trans_choice('{1} Recuperata 1 fattura.|[2,*] Recuperate :count fatture.', $created)
            : __('Nessuna fattura nuova su Openapi.')]);

        return back();
    }

    /**
     * Sandbox only: Openapi sends a test supplier invoice addressed to us to the supplier-invoice callback.
     */
    public function simulate(SimulateSupplierInvoice $simulate): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        try {
            $simulate->handle();
        } catch (OpenapiException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fattura di prova richiesta: arriva tra pochi secondi, poi aggiorna la pagina.')]);

        return back();
    }

    public function updateStatus(Request $request, ReceivedInvoice $receivedInvoice): RedirectResponse
    {
        $this->authorize('create', Expense::class);

        $data = $request->validate(['status' => ['required', Rule::in([ReceivedInvoice::STATUS_NEW, ReceivedInvoice::STATUS_IGNORED])]]);
        abort_if($receivedInvoice->expense_id !== null, 409, __('La fattura è già registrata come spesa.'));

        $receivedInvoice->forceFill(['status' => $data['status']])->save();

        return back();
    }

    public function file(ReceivedInvoice $receivedInvoice): StreamedResponse
    {
        $this->authorize('viewAny', Expense::class);

        abort_unless(Storage::disk(ReceivedInvoice::DISK)->exists($receivedInvoice->file_path), 404);

        $name = $receivedInvoice->filename ?: 'fattura-'.$receivedInvoice->id.(str_ends_with($receivedInvoice->file_path, '.p7m') ? '.xml.p7m' : '.xml');

        return Storage::disk(ReceivedInvoice::DISK)->download($receivedInvoice->file_path, basename($name));
    }
}
