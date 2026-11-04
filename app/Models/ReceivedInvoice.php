<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Electronic invoice received from a supplier (from the SDI through the intermediary, or imported
 * from the files downloaded from "Fatture e Corrispettivi"). Recorded as an expense when confirmed.
 */
#[Fillable([
    'source', 'uuid', 'hash', 'supplier_name', 'supplier_vat', 'document_type', 'number', 'document_date', 'total', 'file_path',
    'filename', 'status', 'ai_extraction_id', 'expense_id', 'received_at',
])]
class ReceivedInvoice extends Model
{
    public const SOURCE_SDI = 'sdi';

    public const SOURCE_IMPORT = 'import';

    public const STATUS_NEW = 'new';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_IGNORED = 'ignored';

    /** Disk of the XML files (private). */
    public const DISK = 'local';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'total' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<AiExtraction, $this>
     */
    public function aiExtraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class);
    }

    public function isCreditNote(): bool
    {
        return in_array($this->document_type, ['TD04', 'TD08'], true);
    }

    /**
     * @param  Builder<ReceivedInvoice>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query, string $term) => $query->where(
            fn (Builder $query) => $query
                ->where('supplier_name', 'like', "%{$term}%")
                ->orWhere('supplier_vat', 'like', "%{$term}%")
                ->orWhere('number', 'like', "%{$term}%"),
        ));
    }
}
