<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessExpenseImportItem;
use App\Models\Expense;
use App\Models\ExpenseImport;
use App\Models\ExpenseImportItem;
use App\Support\Ai\DocumentReader;
use App\Support\FatturaPa\FatturaPaReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Carica più documenti»: the files are uploaded one by one into an import, then read and
 * recorded as expenses in the background (ProcessExpenseImportItem); the import page shows
 * how each one went.
 */
class ExpenseImportController extends Controller
{
    /** Documents in one import. */
    public const MAX_FILES = 50;

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $import = ExpenseImport::create(['user_id' => $request->user()->id]);

        return response()->json(['id' => $import->id], 201);
    }

    /**
     * One document of the import, kept until the import starts.
     */
    public function upload(Request $request, ExpenseImport $import, DocumentReader $reader): JsonResponse
    {
        $this->authorizeImport($request, $import);

        abort_if($import->isStarted(), 409, __('La lettura di questi documenti è già partita.'));
        abort_if($import->items()->count() >= self::MAX_FILES, 422, __('Al massimo :max documenti per volta.', ['max' => self::MAX_FILES]));

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:'.implode(',', DocumentReader::MIMES)],
        ], [], ['file' => 'documento']);

        $file = $request->file('file');

        // Electronic invoices are read without AI; photos and PDFs need the permission.
        if (! FatturaPaReader::looks((string) $file->get(), $file->getClientOriginalName()) && ! $request->user()->can(Permission::AiUse->value)) {
            return response()->json(['message' => __('Per leggere foto e PDF con l\'AI serve il permesso dedicato: puoi caricare solo fatture elettroniche XML.')], 403);
        }

        $stored = $reader->store($file);
        $item = $import->items()->create([
            'original_name' => $stored['name'],
            'file_path' => $stored['path'],
            'mime_type' => $stored['mime_type'],
            'file_size' => $stored['size'],
        ]);

        return response()->json(['id' => $item->id, 'name' => $item->original_name], 201);
    }

    /**
     * All the files are uploaded: the documents are read one after the other in the background.
     */
    public function start(Request $request, ExpenseImport $import): RedirectResponse
    {
        $this->authorizeImport($request, $import);

        if (! $import->isStarted()) {
            // Nothing uploaded: nothing to read, nobody to tell.
            $import->forceFill(['started_at' => now(), 'completed_at' => $import->items()->exists() ? null : now()])->save();

            $import->items()->where('status', ExpenseImportItem::PENDING)->pluck('id')
                ->each(fn (int $id) => ProcessExpenseImportItem::dispatch($id));
        }

        return to_route('purchases.expenses.imports.show', $import);
    }

    public function show(Request $request, ExpenseImport $import): Response
    {
        $this->authorizeImport($request, $import);

        $items = $import->items()->with(['expense', 'supplier', 'extraction'])->orderBy('id')->get();

        return Inertia::render('purchases/expenses/Import', [
            'batch' => [
                'id' => $import->id,
                'created_at' => $import->created_at?->toIso8601String(),
                'started' => $import->isStarted(),
                'completed' => $import->isCompleted(),
                'counts' => collect([ExpenseImportItem::PENDING, ExpenseImportItem::PROCESSING, ExpenseImportItem::RECORDED, ExpenseImportItem::DUPLICATE, ExpenseImportItem::FAILED])
                    ->mapWithKeys(fn (string $status) => [$status => $items->where('status', $status)->count()]),
                'total' => (float) $items->where('status', ExpenseImportItem::RECORDED)->sum(fn (ExpenseImportItem $item) => (float) $item->expense?->total),
            ],
            'items' => $items->map(fn (ExpenseImportItem $item) => [
                'id' => $item->id,
                'name' => $item->original_name,
                'status' => $item->status,
                'error' => $item->error,
                'warnings' => $item->warnings ?? [],
                'supplier' => $item->supplier ? ['id' => $item->supplier->id, 'name' => $item->supplier->name] : null,
                'supplier_source' => $item->supplier_source,
                'expense' => $item->expense ? [
                    'id' => $item->expense->id,
                    'number' => $item->expense->number,
                    'document_date' => $item->expense->document_date?->toDateString(),
                    'type_label' => $item->expense->type->label(),
                    'total' => (float) $item->expense->total,
                    'paid' => $item->expense->paid_at !== null,
                ] : null,
                // A reading that succeeded can be completed by hand in the usual form.
                'extraction_id' => $item->extraction?->succeeded() ? $item->ai_extraction_id : null,
                'file_url' => $item->ai_extraction_id ? route('documents.file', $item->ai_extraction_id) : null,
            ])->values(),
        ]);
    }

    /**
     * A document that failed is read again (e.g. the AI was not reachable).
     */
    public function retry(Request $request, ExpenseImport $import, ExpenseImportItem $item): RedirectResponse
    {
        $this->authorizeImport($request, $import);

        abort_unless($item->status === ExpenseImportItem::FAILED, 409, __('Questo documento non è da riprovare.'));

        $item->forceFill(['status' => ExpenseImportItem::PENDING, 'error' => null])->save();
        $import->forceFill(['completed_at' => null])->save();
        ProcessExpenseImportItem::dispatch($item->id);

        Inertia::flash('toast', ['type' => 'info', 'message' => __('Sto rileggendo :name.', ['name' => $item->original_name])]);

        return back();
    }

    private function authorizeImport(Request $request, ExpenseImport $import): void
    {
        $this->authorize('create', Expense::class);

        abort_unless($import->user_id === $request->user()->id, 403);
    }
}
