<?php

namespace App\Models;

use Database\Factories\VatRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'rate', 'nature', 'legal_reference', 'is_default', 'is_active', 'sort'])]
class VatRate extends Model
{
    /** @use HasFactory<VatRateFactory> */
    use HasFactory;

    /**
     * FatturaPA natures for operations without VAT.
     *
     * @var array<string, string>
     */
    public const NATURES = [
        'N1' => 'N1 – Escluse ex art. 15',
        'N2.1' => 'N2.1 – Non soggette (artt. 7–7-septies)',
        'N2.2' => 'N2.2 – Non soggette, altri casi',
        'N3.1' => 'N3.1 – Non imponibili, esportazioni',
        'N3.2' => 'N3.2 – Non imponibili, cessioni intracomunitarie',
        'N3.3' => 'N3.3 – Non imponibili, cessioni verso San Marino',
        'N3.4' => 'N3.4 – Non imponibili, operazioni assimilate alle esportazioni',
        'N3.5' => "N3.5 – Non imponibili, a seguito di dichiarazioni d'intento",
        'N3.6' => 'N3.6 – Non imponibili, altre operazioni',
        'N4' => 'N4 – Esenti',
        'N5' => 'N5 – Regime del margine',
        'N6.1' => 'N6.1 – Inversione contabile, rottami',
        'N6.2' => 'N6.2 – Inversione contabile, oro e argento',
        'N6.3' => 'N6.3 – Inversione contabile, subappalto edilizia',
        'N6.4' => 'N6.4 – Inversione contabile, fabbricati',
        'N6.5' => 'N6.5 – Inversione contabile, telefoni cellulari',
        'N6.6' => 'N6.6 – Inversione contabile, prodotti elettronici',
        'N6.7' => 'N6.7 – Inversione contabile, comparto edile',
        'N6.8' => 'N6.8 – Inversione contabile, settore energetico',
        'N6.9' => 'N6.9 – Inversione contabile, altri casi',
        'N7' => 'N7 – IVA assolta in altro stato UE',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @param  Builder<VatRate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<VatRate>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderByDesc('rate')->orderBy('name');
    }

    public static function default(): ?self
    {
        return static::query()->active()->where('is_default', true)->first()
            ?? static::query()->active()->ordered()->first();
    }
}
