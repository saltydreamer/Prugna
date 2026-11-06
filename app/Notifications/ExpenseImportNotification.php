<?php

namespace App\Notifications;

use App\Models\ExpenseImport;
use App\Models\ExpenseImportItem;

/**
 * The documents uploaded together have all been read: how many expenses were recorded.
 */
class ExpenseImportNotification extends AppNotification
{
    public function __construct(public ExpenseImport $import) {}

    public function title(): string
    {
        return __('Spese dai documenti: lettura completata');
    }

    public function message(): string
    {
        $counts = $this->import->items()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $recorded = (int) ($counts[ExpenseImportItem::RECORDED] ?? 0);
        $duplicates = (int) ($counts[ExpenseImportItem::DUPLICATE] ?? 0);
        $failed = (int) ($counts[ExpenseImportItem::FAILED] ?? 0);

        return collect([
            trans_choice('{1} 1 spesa registrata|[0,*] :count spese registrate', $recorded, ['count' => $recorded]),
            $duplicates ? trans_choice('{1} 1 già presente|[2,*] :count già presenti', $duplicates, ['count' => $duplicates]) : null,
            $failed ? __(':count da completare a mano', ['count' => $failed]) : null,
        ])->filter()->implode(', ').'.';
    }

    public function icon(): string
    {
        return 'mdi-receipt-text-check-outline';
    }

    public function color(): string
    {
        return $this->import->items()->where('status', ExpenseImportItem::FAILED)->exists() ? 'warning' : 'success';
    }

    public function actionUrl(): ?string
    {
        return route('purchases.expenses.imports.show', $this->import);
    }

    public function actionLabel(): ?string
    {
        return __('Vedi le spese');
    }
}
