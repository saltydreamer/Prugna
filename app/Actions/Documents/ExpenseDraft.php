<?php

namespace App\Actions\Documents;

use App\Enums\ExpenseType;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\QuoteLineType;
use App\Models\AiExtraction;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\VatRate;
use App\Rules\ItalianTaxCode;
use App\Rules\ItalianVatNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns the data read from a document (AI or FatturaPA XML) into the defaults of the expense form,
 * matching the supplier and the VAT rates of the application. Nothing is saved here.
 */
class ExpenseDraft
{
    /**
     * @return array{defaults: array<string, mixed>, review: array<string, mixed>}
     */
    public function fromExtraction(AiExtraction $extraction): array
    {
        $data = $extraction->result ?? [];
        $warnings = array_values(array_filter((array) ($data['warnings'] ?? []), 'is_string'));

        [$supplierId, $suggestion, $supplierWarning] = $this->matchSupplier((array) ($data['supplier'] ?? []));

        if ($supplierWarning) {
            $warnings[] = $supplierWarning;
        }

        $type = ExpenseType::tryFrom((string) ($data['document_type'] ?? '')) ?? ExpenseType::Invoice;
        $date = $this->date($data['date'] ?? null) ?? today()->toDateString();
        $method = $this->paymentMethod($data['payment_method'] ?? null);
        $lines = $this->lines($data, $warnings);

        $computed = $this->total($lines);

        $documentTotal = isset($data['total']) && is_numeric($data['total']) ? round((float) $data['total'], 2) : null;

        if ($documentTotal !== null && abs($computed - $documentTotal) > 0.02) {
            $warnings[] = __('Il totale calcolato dalle righe (:computed) è diverso da quello del documento (:document): controlla importi e aliquote.', [
                'computed' => number_format($computed, 2, ',', '.'),
                'document' => number_format($documentTotal, 2, ',', '.'),
            ]);
        }

        $number = $this->string($data['number'] ?? null, 64);
        $duplicate = $supplierId && $number
            ? Expense::query()->where('supplier_id', $supplierId)->where('number', $number)->whereYear('document_date', substr($date, 0, 4))->first()
            : null;

        return [
            'defaults' => [
                'type' => $type->value,
                'supplier_id' => $supplierId,
                'number' => $number,
                'document_date' => $date,
                'title' => $this->string($data['description'] ?? null, 255),
                'payment_method' => $method?->value,
                'due_date' => $this->date($data['due_date'] ?? null),
                // Receipts and card/cash purchases are paid on the spot.
                'paid_at' => $type === ExpenseType::Receipt || $method?->isImmediate() ? $date : null,
                'lines' => $lines,
                'ai_extraction_id' => $extraction->id,
            ],
            'review' => [
                'source' => $extraction->source,
                'model' => $extraction->model,
                'cost' => $extraction->cost !== null ? (float) $extraction->cost : null,
                'file_name' => $extraction->original_name,
                'file_url' => route('documents.file', $extraction),
                'file_mime' => $extraction->mime_type,
                'document_total' => $documentTotal,
                'supplier_suggestion' => $suggestion,
                // The same supplier document is already recorded (e.g. read before from a screenshot).
                'duplicate' => $duplicate ? [
                    'id' => $duplicate->id,
                    'label' => trim($duplicate->type->label().' '.$duplicate->number),
                    'date' => $duplicate->document_date->toDateString(),
                ] : null,
                'warnings' => array_values(array_unique($warnings)),
            ],
        ];
    }

    /**
     * Supplier by VAT number, tax code or name. A customer can't be used as supplier.
     *
     * @param  array<string, mixed>  $supplier
     * @return array{0: int|null, 1: array<string, mixed>|null, 2: string|null}
     */
    private function matchSupplier(array $supplier): array
    {
        $vatNumber = filled($supplier['vat_number'] ?? null) ? ItalianVatNumber::normalize((string) $supplier['vat_number']) : null;
        $taxCode = filled($supplier['tax_code'] ?? null) ? ItalianTaxCode::normalize((string) $supplier['tax_code']) : null;
        $name = $this->string($supplier['name'] ?? null, 255);

        $match = Organization::query()
            ->where(fn ($query) => $query
                ->when($vatNumber, fn ($query) => $query->orWhere('vat_number', $vatNumber))
                ->when($taxCode, fn ($query) => $query->orWhere('tax_code', $taxCode))
                ->when(! $vatNumber && ! $taxCode && $name, fn ($query) => $query->orWhere('name', $name)))
            ->when(! $vatNumber && ! $taxCode && ! $name, fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        if ($match?->role === OrganizationRole::Supplier) {
            return [$match->id, null, null];
        }

        if ($match) {
            return [null, null, __(':name è in anagrafica come cliente: un cliente non può essere anche fornitore. Scegli o crea il fornitore corretto.', ['name' => $match->name])];
        }

        $suggestion = $name ? [
            'name' => $name,
            'vat_number' => $vatNumber,
            'tax_code' => $taxCode,
            'email' => $this->string($supplier['email'] ?? null, 255),
            'pec' => $this->string($supplier['pec'] ?? null, 255),
            'phone' => $this->string($supplier['phone'] ?? null, 32),
            'location' => [
                'address' => $this->string($supplier['address'] ?? null, 255),
                'postal_code' => $this->string($supplier['postal_code'] ?? null, 16),
                'city' => $this->string($supplier['city'] ?? null, 255),
                'province' => $this->string(strtoupper((string) ($supplier['province'] ?? '')), 8),
                'country' => strtoupper((string) ($supplier['country'] ?? '')) ?: 'IT',
            ],
        ] : null;

        return [null, $suggestion, $name ? null : __('Il fornitore non è leggibile: sceglilo dall\'elenco.')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function lines(array $data, array &$warnings): array
    {
        $rates = VatRate::query()->where('is_active', true)->ordered()->get();
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line) || blank($line['description'] ?? null)) {
                continue;
            }

            $quantity = (float) ($line['quantity'] ?? 1) ?: 1.0;
            $unitPrice = isset($line['unit_price']) && is_numeric($line['unit_price'])
                ? (float) $line['unit_price']
                : round((float) ($line['total'] ?? 0) / $quantity, 4);

            $lines[] = [
                'type' => QuoteLineType::Item->value,
                'description' => $this->string($line['description'], 5000),
                'unit' => null,
                'quantity' => round($quantity, 3),
                'unit_price' => round($unitPrice, 4),
                'discount_percent' => 0,
                'vat_rate_id' => $this->vatRate($rates, (float) ($line['vat_rate'] ?? 0), $line['vat_nature'] ?? null, $warnings),
                'rebill' => false,
                'markup_percent' => 0,
            ];
        }

        // Nothing readable: one line with the taxable amount.
        if ($lines === [] && is_numeric($data['total_taxable'] ?? $data['total'] ?? null)) {
            $taxable = (float) ($data['total_taxable'] ?? $data['total']);
            $rate = $taxable > 0 && is_numeric($data['total_vat'] ?? null) ? round((float) $data['total_vat'] / $taxable * 100) : 0;

            $lines[] = [
                'type' => QuoteLineType::Item->value,
                'description' => $this->string($data['description'] ?? null, 5000) ?? __('Spesa'),
                'unit' => null,
                'quantity' => 1,
                'unit_price' => round($taxable, 4),
                'discount_percent' => 0,
                'vat_rate_id' => $this->vatRate($rates, (float) $rate, null, $warnings),
                'rebill' => false,
                'markup_percent' => 0,
            ];
        }

        return $lines;
    }

    /**
     * @param  Collection<int, VatRate>  $rates
     * @param  list<string>  $warnings
     */
    private function vatRate(Collection $rates, float $rate, ?string $nature, array &$warnings): ?int
    {
        if ($rate > 0) {
            $match = $rates->first(fn (VatRate $vatRate) => abs((float) $vatRate->rate - $rate) < 0.01 && blank($vatRate->nature));

            if (! $match) {
                $warnings[] = __("L'aliquota IVA :rate% non è tra quelle configurate: controlla le righe.", ['rate' => rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',')]);
            }

            return $match?->id ?? VatRate::default()?->id;
        }

        $zero = $rates->filter(fn (VatRate $vatRate) => (float) $vatRate->rate == 0.0);

        $match = ($nature ? $zero->first(fn (VatRate $vatRate) => $vatRate->nature === $nature) ?? $zero->first(fn (VatRate $vatRate) => str_starts_with((string) $vatRate->nature, substr($nature, 0, 2))) : null)
            ?? $zero->firstWhere('nature', 'N1')
            ?? $zero->first()
            ?? VatRate::default();

        return $match?->id;
    }

    /**
     * Document total of the draft lines, Italian style (VAT on the summary per rate).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function total(array $lines): float
    {
        $rates = VatRate::query()->whereIn('id', array_filter(array_column($lines, 'vat_rate_id')))->pluck('rate', 'id');
        $groups = [];

        foreach ($lines as $line) {
            $rate = (float) ($rates[$line['vat_rate_id']] ?? 0);
            $groups[(string) $rate] = ($groups[(string) $rate] ?? 0) + DocumentTotals::lineTotal((float) $line['quantity'], (float) $line['unit_price'], (float) $line['discount_percent']);
        }

        $total = 0.0;

        foreach ($groups as $rate => $taxable) {
            $taxable = round($taxable, 2);
            $total += $taxable + round($taxable * (float) $rate / 100, 2);
        }

        return round($total, 2);
    }

    private function paymentMethod(mixed $value): ?PaymentMethod
    {
        $value = (string) $value;

        return str_starts_with($value, 'MP') ? PaymentMethod::fromFatturaPa($value) : PaymentMethod::tryFrom($value);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function string(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
