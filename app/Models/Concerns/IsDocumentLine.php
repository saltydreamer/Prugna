<?php

namespace App\Models\Concerns;

use App\Enums\QuoteLineType;

/**
 * Shared behaviour of quote, order and invoice lines (item / section / text).
 *
 * @property QuoteLineType $type
 */
trait IsDocumentLine
{
    public function isItem(): bool
    {
        return $this->type === QuoteLineType::Item;
    }

    /**
     * Whether the line counts in the totals (optional lines only when selected).
     */
    public function isIncluded(): bool
    {
        if (! $this->isItem()) {
            return false;
        }

        $optional = (bool) ($this->attributes['is_optional'] ?? false);

        return ! $optional || (bool) ($this->attributes['is_selected'] ?? true);
    }
}
