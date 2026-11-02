<?php

namespace App\Models\Concerns;

/**
 * Cost lines (supplier orders, expenses) that can be charged back to a customer with a markup.
 *
 * @property bool $rebill
 */
trait IsRebillableCost
{
    /**
     * Unit price charged to the customer: net cost plus markup.
     */
    public function rebillUnitPrice(): float
    {
        return round((float) $this->unit_price * (1 - (float) $this->discount_percent / 100) * (1 + (float) $this->markup_percent / 100), 4);
    }

    public function rebillAmount(): float
    {
        return round((float) $this->quantity * $this->rebillUnitPrice(), 2);
    }
}
