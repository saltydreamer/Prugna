<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'method', 'days', 'end_of_month', 'description', 'is_default', 'is_active', 'sort'])]
class PaymentTerm extends Model
{
    use HasFactory;

    /**
     * FatturaPA "ModalitaPagamento" codes.
     *
     * @var array<string, string>
     */
    public const METHODS = [
        'MP01' => 'Contanti',
        'MP02' => 'Assegno',
        'MP05' => 'Bonifico',
        'MP08' => 'Carta di pagamento',
        'MP12' => 'Ri.Ba.',
        'MP16' => 'Domiciliazione bancaria',
        'MP19' => 'SEPA Direct Debit',
        'MP23' => 'PagoPA',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'end_of_month' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Organization, $this>
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * Due date for a document issued on the given date ("d.f.f.m." = end of month).
     */
    public function dueDate(CarbonInterface $issuedOn): CarbonInterface
    {
        $due = $issuedOn->copy()->addDays($this->days);

        return $this->end_of_month ? $due->endOfMonth()->startOfDay() : $due;
    }

    /**
     * @param  Builder<PaymentTerm>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<PaymentTerm>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('name');
    }

    public static function default(): ?self
    {
        return static::query()->active()->where('is_default', true)->first();
    }
}
