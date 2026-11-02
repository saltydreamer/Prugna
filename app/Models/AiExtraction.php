<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document read by the AI (or parsed from FatturaPA XML) and the data extracted from it.
 * Records are created by App\Support\Ai\DocumentReader only.
 */
class AiExtraction extends Model
{
    public const KIND_EXPENSE = 'expense';

    public const KIND_ORGANIZATION = 'organization';

    public const KIND_TEST = 'test';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
            'cost' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
