<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document of an expense import: read, then recorded as an expense, skipped as already
 * recorded, or failed (it can then be completed by hand from its reading).
 */
class ExpenseImportItem extends Model
{
    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const RECORDED = 'recorded';

    public const DUPLICATE = 'duplicate';

    public const FAILED = 'failed';

    /** The supplier was already in the registry, was created from the document, or is the generic one. */
    public const SUPPLIER_MATCHED = 'matched';

    public const SUPPLIER_CREATED = 'created';

    public const SUPPLIER_GENERIC = 'generic';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'file_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ExpenseImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ExpenseImport::class, 'expense_import_id');
    }

    /**
     * @return BelongsTo<AiExtraction, $this>
     */
    public function extraction(): BelongsTo
    {
        return $this->belongsTo(AiExtraction::class, 'ai_extraction_id');
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_id')->withTrashed();
    }

    public function isWaiting(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING], true);
    }
}
