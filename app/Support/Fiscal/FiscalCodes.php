<?php

namespace App\Support\Fiscal;

/**
 * FatturaPA code lists used in settings and XML.
 */
final class FiscalCodes
{
    /**
     * RegimeFiscale.
     *
     * @var array<string, string>
     */
    public const REGIMES = [
        'RF01' => 'RF01 – Ordinario',
        'RF02' => 'RF02 – Contribuenti minimi',
        'RF19' => 'RF19 – Forfettario',
        'RF18' => 'RF18 – Altro',
    ];

    /**
     * Regimes without VAT on sales.
     */
    public const VAT_FREE_REGIMES = ['RF02', 'RF19'];

    /**
     * TipoRitenuta.
     *
     * @var array<string, string>
     */
    public const WITHHOLDING_TYPES = [
        'RT01' => 'RT01 – Persone fisiche',
        'RT02' => 'RT02 – Persone giuridiche',
    ];

    /**
     * CausalePagamento (most common).
     *
     * @var array<string, string>
     */
    public const WITHHOLDING_REASONS = [
        'A' => 'A – Lavoro autonomo professionale',
        'M' => 'M – Lavoro autonomo occasionale',
        'R' => 'R – Agenti e rappresentanti',
        'W' => 'W – Contratti di appalto',
    ];

    /**
     * TipoCassa.
     *
     * @var array<string, string>
     */
    public const PENSION_FUNDS = [
        'TC01' => 'TC01 – Avvocati',
        'TC02' => 'TC02 – Dottori commercialisti',
        'TC03' => 'TC03 – Geometri',
        'TC04' => 'TC04 – Ingegneri e architetti',
        'TC05' => 'TC05 – Notariato',
        'TC06' => 'TC06 – Ragionieri ed esperti contabili',
        'TC07' => 'TC07 – ENASARCO',
        'TC09' => 'TC09 – ENPAM (medici)',
        'TC10' => 'TC10 – ENPAF (farmacisti)',
        'TC12' => 'TC12 – ENPAPI (infermieri)',
        'TC15' => 'TC15 – INPGI (giornalisti)',
        'TC18' => 'TC18 – EPAP',
        'TC19' => 'TC19 – EPPI (periti industriali)',
        'TC20' => 'TC20 – ENPAB (biologi)',
        'TC21' => 'TC21 – ENPAP (psicologi)',
        'TC22' => 'TC22 – INPS (rivalsa / gestione separata)',
    ];

    /**
     * Virtual stamp duty on documents without VAT above the threshold.
     */
    public const STAMP_DUTY_AMOUNT = 2.0;

    public const STAMP_DUTY_THRESHOLD = 77.47;

    /**
     * @param  array<string, string>  $codes
     * @return list<array{value: string, title: string}>
     */
    public static function options(array $codes): array
    {
        return array_map(fn (string $value, string $title) => ['value' => $value, 'title' => $title], array_keys($codes), array_values($codes));
    }
}
