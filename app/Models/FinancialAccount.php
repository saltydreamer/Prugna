<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use Carbon\CarbonInterface;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank account, card, cash: opening balance plus its movements.
 */
#[Fillable(['name', 'type', 'iban', 'opening_balance', 'opening_date', 'is_active', 'sort'])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'opening_balance' => 'decimal:2',
            'opening_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AccountMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(AccountMovement::class);
    }

    /**
     * Balance at the end of the day (today when null): the opening balance plus the movements from
     * its day on. Earlier movements (e.g. collections recovered from Dolibarr) are history: they
     * count in the profit and loss, not in the balance.
     */
    public function balance(?CarbonInterface $date = null): float
    {
        $movements = (float) $this->movements()
            ->when($this->opening_date, fn (Builder $query) => $query->whereDate('date', '>=', $this->opening_date->toDateString()))
            ->when($date, fn (Builder $query, CarbonInterface $date) => $query->whereDate('date', '<=', $date->toDateString()))
            ->sum('amount');

        return round((float) $this->opening_balance + $movements, 2);
    }

    /**
     * @param  Builder<FinancialAccount>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    /**
     * @return list<array{value: int, title: string}>
     */
    public static function options(): array
    {
        return self::query()->where('is_active', true)->ordered()->get()
            ->map(fn (self $account) => ['value' => $account->id, 'title' => $account->name])
            ->all();
    }
}
