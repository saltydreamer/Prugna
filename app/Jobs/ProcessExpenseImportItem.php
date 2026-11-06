<?php

namespace App\Jobs;

use App\Actions\Expenses\ImportExpenseDocument;
use App\Models\ExpenseImportItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads and records one document of an expense import in the background.
 */
class ProcessExpenseImportItem implements ShouldQueue
{
    use Queueable;

    /** An AI reading is paid: never repeated by the queue (the user can retry from the import page). */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $itemId) {}

    public function handle(ImportExpenseDocument $action): void
    {
        $item = ExpenseImportItem::query()->with('import.user')->find($this->itemId);

        if (! $item || ! $item->isWaiting()) {
            return;
        }

        try {
            $action->handle($item);
        } finally {
            $action->completeIfDone($item->import);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $item = ExpenseImportItem::query()->with('import')->find($this->itemId);

        if (! $item || ! $item->isWaiting()) {
            return;
        }

        $action = app(ImportExpenseDocument::class);
        $action->fail($item, __('Lettura non riuscita: :error', ['error' => $exception?->getMessage() ?: __('errore imprevisto')]));
        $action->completeIfDone($item->import);
    }
}
