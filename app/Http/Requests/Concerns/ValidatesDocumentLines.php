<?php

namespace App\Http\Requests\Concerns;

use App\Enums\QuoteLineType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation of the "lines" of orders and invoices (same shape as quote lines).
 */
trait ValidatesDocumentLines
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.type' => ['required', Rule::enum(QuoteLineType::class)],
            'lines.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'lines.*.code' => ['nullable', 'string', 'max:64'],
            'lines.*.description' => ['required', 'string', 'max:5000'],
            'lines.*.unit' => ['nullable', 'string', 'max:16'],
            'lines.*.quantity' => ['exclude_unless:lines.*.type,item', 'required', 'numeric', 'gt:0', 'max:9999999'],
            'lines.*.unit_price' => ['exclude_unless:lines.*.type,item', 'required', 'numeric', 'between:-99999999,99999999'],
            'lines.*.discount_percent' => ['exclude_unless:lines.*.type,item', 'nullable', 'numeric', 'between:0,100'],
            'lines.*.vat_rate_id' => ['exclude_unless:lines.*.type,item', 'required', 'integer', Rule::exists('vat_rates', 'id')],
        ];
    }

    protected function requireItemLine(Validator $validator): void
    {
        if (! $this->has('lines')) {
            return;
        }

        $items = collect($this->input('lines', []))->where('type', QuoteLineType::Item->value);

        if ($items->isEmpty()) {
            $validator->errors()->add('lines', __('Aggiungi almeno una riga con un articolo o un servizio.'));
        }
    }

    /**
     * @return array<string, string>
     */
    protected function lineAttributes(): array
    {
        return [
            'lines' => 'righe',
            'lines.*.description' => 'descrizione',
            'lines.*.quantity' => 'quantità',
            'lines.*.unit_price' => 'prezzo',
            'lines.*.discount_percent' => 'sconto',
            'lines.*.vat_rate_id' => 'IVA',
        ];
    }
}
