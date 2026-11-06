<?php

namespace App\Actions\Expenses;

use App\Actions\Documents\DocumentTotals;
use App\Actions\Documents\SyncDocumentLines;
use App\Models\AccountingCategory;
use App\Models\AiExtraction;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Organization;
use App\Support\Ai\DocumentReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates and updates expenses: supplier snapshot, lines with VAT, totals, attachment
 * (uploaded, or the document read by the AI).
 */
class SaveExpense
{
    public function __construct(private SyncDocumentLines $syncLines) {}

    /**
     * @param  array<string, mixed>  $data  Header fields plus "lines", "attachment" (UploadedFile) and "ai_extraction_id".
     */
    public function handle(Expense $expense, array $data): Expense
    {
        $lines = $data['lines'] ?? null;
        $attachment = $data['attachment'] ?? null;
        $extractionId = $data['ai_extraction_id'] ?? null;
        unset($data['lines'], $data['attachment'], $data['ai_extraction_id']);

        return DB::transaction(function () use ($expense, $data, $lines, $attachment, $extractionId): Expense {
            $expense->fill($data);
            // Share used for the business: the category's default unless given.
            $expense->business_share ??= $expense->category_id ? (AccountingCategory::query()->whereKey($expense->category_id)->value('business_share') ?? 100) : 100;

            // Costs of a customer order are charged to that order's customer.
            if ($expense->order_id) {
                $expense->customer_id = Order::find($expense->order_id)?->organization_id;
            }

            $supplier = $expense->supplier_id ? Organization::withTrashed()->find($expense->supplier_id) : null;
            $expense->supplier = $supplier ? [
                'name' => $supplier->name,
                'vat_number' => $supplier->vat_number,
                'tax_code' => $supplier->tax_code,
            ] : ($expense->supplier ?? ['name' => null]);

            if ($extractionId && ! $expense->exists && ($extraction = AiExtraction::find($extractionId))) {
                $expense->ai_extraction_id = $extraction->id;
                $expense->source = $extraction->source;
                $expense->forceFill([
                    'attachment_path' => $extraction->file_path,
                    'attachment_name' => $extraction->original_name,
                    'attachment_mime' => $extraction->mime_type,
                ]);
            }

            if ($attachment instanceof UploadedFile) {
                $this->replaceAttachment($expense, $attachment);
            }

            $expense->save();

            if ($lines !== null) {
                $this->syncLines->handle($expense->lines(), array_map(
                    // Without a customer there is nobody to charge the cost to.
                    fn (array $line) => [...$line, 'rebill' => $expense->customer_id ? (bool) ($line['rebill'] ?? false) : false, 'markup_percent' => (float) ($line['markup_percent'] ?? 0)],
                    $lines,
                ), ['rebill', 'markup_percent']);
            }

            $totals = DocumentTotals::summarize($expense->lines()->get());
            $expense->forceFill([
                'subtotal' => $totals['subtotal'],
                'vat_total' => $totals['vat_total'],
                'total' => $totals['total'],
            ])->save();

            return $expense;
        });
    }

    private function replaceAttachment(Expense $expense, UploadedFile $file): void
    {
        $previous = $expense->attachment_path;
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');

        $expense->forceFill([
            'attachment_path' => Storage::disk(DocumentReader::DISK)->putFileAs('documents/'.now()->format('Y/m'), $file, Str::uuid().'.'.$extension),
            'attachment_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'attachment_mime' => $file->getMimeType(),
        ]);

        // Files shared with an AI reading are kept with it.
        if ($previous && ! AiExtraction::query()->where('file_path', $previous)->exists()) {
            Storage::disk(DocumentReader::DISK)->delete($previous);
        }
    }
}
