<?php

namespace App\Actions\Interventions;

use App\Actions\Catalog\ResolvePrice;
use App\Actions\Documents\DocumentTotals;
use App\Actions\Invoices\RegimeVatRate;
use App\Enums\QuoteLineType;
use App\Models\Intervention;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\VatRate;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Invoice lines for interventions: hours (rounded) priced with the service of the category for the mode,
 * plus call-out, km, travel time and materials for on-site work. One set of lines per intervention,
 * or grouped: one line per category and mode for the hours, one per extra.
 * Every line lists in "interventions" the ids it bills.
 */
class BillInterventions
{
    /** @var array<int, array{price: float}> */
    private array $prices = [];

    /** @var array<int, Product|null> */
    private array $products = [];

    public function __construct(private Settings $settings, private ResolvePrice $resolvePrice, private RegimeVatRate $vatRates) {}

    /**
     * Interventions of the customer (optionally of one order) still to invoice, oldest first.
     *
     * @return Collection<int, Intervention>
     */
    public function available(int $customerId, ?Order $order = null): Collection
    {
        return Intervention::query()
            ->toInvoice()
            ->where('organization_id', $customerId)
            ->when($order, fn (Builder $query) => $query->where('order_id', $order->id))
            ->with(['category.remoteProduct', 'category.onsiteProduct', 'technician:id,name', 'materials', 'order:id,number', 'organization'])
            ->orderBy('started_at')
            ->get();
    }

    /**
     * @param  Collection<int, Intervention>  $interventions  Same customer.
     * @return list<array<string, mixed>>
     */
    public function lines(Collection $interventions, bool $grouped = false, bool $listDetails = true): array
    {
        $interventions = $interventions->sortBy('started_at')->values();
        $interventions->loadMissing(['category.remoteProduct', 'category.onsiteProduct', 'materials', 'organization']);

        if ($interventions->isEmpty()) {
            return [];
        }

        $this->checkProducts($interventions);

        return $grouped ? $this->groupedLines($interventions, $listDetails) : $interventions->flatMap(fn (Intervention $intervention) => $this->singleLines($intervention))->values()->all();
    }

    /**
     * Summary for the picker, with the amount it would be invoiced at (or what prevents it).
     *
     * @return array<string, mixed>
     */
    public function option(Intervention $intervention): array
    {
        try {
            $this->checkProducts(collect([$intervention]));
            $amount = collect($this->singleLines($intervention))->sum(fn (array $line) => DocumentTotals::lineTotal((float) $line['quantity'], (float) $line['unit_price']));
            $problem = null;
        } catch (ValidationException $exception) {
            $amount = null;
            $problem = collect($exception->errors())->flatten()->first();
        }

        return [
            'id' => $intervention->id,
            'number' => $intervention->number,
            'started_at' => $intervention->started_at->toIso8601String(),
            'category' => $intervention->category?->name,
            'mode' => $intervention->mode->value,
            'mode_label' => $intervention->mode->label(),
            'technician' => $intervention->technician?->name,
            'description' => mb_strimwidth((string) strtok((string) $intervention->description, "\n"), 0, 140, '…'),
            'duration_minutes' => $intervention->duration_minutes,
            'billable_minutes' => $intervention->billableMinutes(),
            'invoiceable_minutes' => $intervention->invoiceableMinutes(),
            'contract_minutes' => $intervention->contract_minutes,
            'travel_minutes' => $intervention->billableTravelMinutes(),
            'km' => $intervention->isOnsite() ? (float) $intervention->km : 0,
            'callout' => $intervention->isOnsite() && $intervention->callout,
            'materials_count' => $intervention->isOnsite() ? $intervention->materials->count() : 0,
            'order' => $intervention->order ? ['id' => $intervention->order->id, 'number' => $intervention->order->number] : null,
            'amount' => $amount === null ? null : round($amount, 2),
            'problem' => $problem,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function singleLines(Intervention $intervention): array
    {
        $customer = $intervention->organization;
        $reference = $intervention->localStart()->format('d/m/Y').' ('.$intervention->number.')';
        $lines = [];

        if ($intervention->invoiceableMinutes() > 0) {
            $product = $intervention->category->productFor($intervention->mode);
            $description = $this->hoursTitle($intervention).' – '.$reference.$this->contractNote($intervention);

            if (trim((string) $intervention->description) !== '') {
                $description .= "\n".trim((string) $intervention->description);
            }

            $lines[] = $this->productLine($product, $customer, $description, self::hours($intervention->invoiceableMinutes()), [$intervention->id]);
        }

        foreach ($this->extras(collect([$intervention]), $customer, fn (string $title) => "{$title} – {$reference}") as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  Collection<int, Intervention>  $interventions
     * @return list<array<string, mixed>>
     */
    private function groupedLines(Collection $interventions, bool $listDetails): array
    {
        $customer = $interventions->first()->organization;
        $lines = [];

        $groups = $interventions->filter(fn (Intervention $intervention) => $intervention->invoiceableMinutes() > 0)
            ->groupBy(fn (Intervention $intervention) => $intervention->category_id.'|'.$intervention->mode->value);

        foreach ($groups as $group) {
            /** @var Intervention $first */
            $first = $group->first();
            $minutes = $group->sum(fn (Intervention $intervention) => $intervention->invoiceableMinutes());
            $description = $this->hoursTitle($first).', '.self::period($group).' ('.self::hoursLabel($minutes).')';

            if ($listDetails) {
                $description .= "\n".$group->map(fn (Intervention $intervention) => '• '.implode(' – ', array_filter([
                    $intervention->localStart()->format('d/m/Y').' '.$intervention->number,
                    self::hoursLabel($intervention->invoiceableMinutes()).$this->contractNote($intervention),
                    mb_strimwidth(trim((string) strtok((string) $intervention->description, "\n")), 0, 120, '…'),
                ])))->implode("\n");
            }

            $lines[] = $this->productLine($first->category->productFor($first->mode), $customer, $description, self::hours($minutes), $group->pluck('id')->all());
        }

        $period = self::period($interventions);

        return [...$lines, ...$this->extras($interventions, $customer, fn (string $title) => "{$title}, {$period}")];
    }

    /**
     * Call-out, km, travel time and materials of the on-site interventions, one line each (summed when several).
     *
     * @param  Collection<int, Intervention>  $interventions
     * @param  \Closure(string): string  $describe
     * @return list<array<string, mixed>>
     */
    private function extras(Collection $interventions, Organization $customer, \Closure $describe): array
    {
        $onsite = $interventions->filter(fn (Intervention $intervention) => $intervention->isOnsite())->values();
        $lines = [];

        $callouts = $onsite->filter(fn (Intervention $intervention) => $intervention->callout);
        if ($callouts->isNotEmpty()) {
            $lines[] = $this->productLine($this->setting('callout'), $customer, $describe(__('Diritto di uscita')), $callouts->count(), $callouts->pluck('id')->all());
        }

        $travel = $onsite->filter(fn (Intervention $intervention) => $intervention->billableTravelMinutes() > 0);
        if ($travel->isNotEmpty()) {
            $minutes = $travel->sum(fn (Intervention $intervention) => $intervention->billableTravelMinutes());
            $lines[] = $this->productLine($this->setting('travel'), $customer, $describe(__('Tempo di viaggio')).' ('.self::hoursLabel($minutes).')', self::hours($minutes), $travel->pluck('id')->all());
        }

        $km = $onsite->filter(fn (Intervention $intervention) => (float) $intervention->km > 0);
        if ($km->isNotEmpty()) {
            $total = round($km->sum(fn (Intervention $intervention) => (float) $intervention->km), 1);
            $lines[] = $this->productLine($this->setting('km'), $customer, $describe(__('Rimborso chilometrico')).' ('.self::number($total).' km)', $total, $km->pluck('id')->all());
        }

        $materials = $onsite->flatMap(fn (Intervention $intervention) => $intervention->materials->map(fn ($material) => [$intervention, $material]))
            ->groupBy(fn (array $pair) => implode('|', [$pair[1]->product_id, $pair[1]->code, $pair[1]->description, $pair[1]->unit, (float) $pair[1]->unit_price, $pair[1]->vat_rate_id]));

        foreach ($materials as $pairs) {
            $material = $pairs->first()[1];
            $vatRateId = $material->vat_rate_id ?? Product::query()->whereKey($material->product_id)->value('vat_rate_id') ?? VatRate::default()?->id;

            $lines[] = [
                'type' => QuoteLineType::Item,
                'product_id' => $material->product_id,
                'code' => $material->code,
                'description' => $material->description,
                'unit' => $material->unit,
                'quantity' => round($pairs->sum(fn (array $pair) => (float) $pair[1]->quantity), 3),
                'unit_price' => (float) $material->unit_price,
                'discount_percent' => 0,
                'vat_rate_id' => $this->vatRates->for($vatRateId),
                'interventions' => $pairs->map(fn (array $pair) => $pair[0]->id)->unique()->values()->all(),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function productLine(Product $product, Organization $customer, string $description, float $quantity, array $ids): array
    {
        $this->prices[$product->id] ??= $this->resolvePrice->handle($product, $customer);

        return [
            'type' => QuoteLineType::Item,
            'product_id' => $product->id,
            'code' => $product->code,
            'description' => mb_substr($description, 0, 5000),
            'unit' => $product->unit?->value,
            'quantity' => $quantity,
            'unit_price' => (float) $this->prices[$product->id]['price'],
            'discount_percent' => 0,
            'vat_rate_id' => $this->vatRates->for($product->vat_rate_id),
            'interventions' => array_values(array_unique($ids)),
        ];
    }

    /**
     * Every service needed must be configured, otherwise nothing can be priced.
     *
     * @param  Collection<int, Intervention>  $interventions
     */
    private function checkProducts(Collection $interventions): void
    {
        $errors = [];

        foreach ($interventions as $intervention) {
            if ($intervention->invoiceableMinutes() > 0 && ! $intervention->category?->productFor($intervention->mode)) {
                $errors[] = __('La categoria «:category» non ha il servizio per gli interventi :mode: impostalo in Impostazioni interventi.', [
                    'category' => $intervention->category?->name,
                    'mode' => mb_strtolower($intervention->mode->label()),
                ]);
            }

            if (! $intervention->isOnsite()) {
                continue;
            }

            foreach (['callout' => $intervention->callout, 'travel' => $intervention->billableTravelMinutes() > 0, 'km' => (float) $intervention->km > 0] as $extra => $used) {
                if ($used && ! $this->setting($extra, false)) {
                    $errors[] = __('Manca il servizio per :extra: impostalo in Impostazioni interventi.', ['extra' => self::extraLabel($extra)]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['interventions' => array_values(array_unique($errors))]);
        }
    }

    private function setting(string $extra, bool $required = true): ?Product
    {
        $id = (int) $this->settings->get("interventions.{$extra}_product_id");
        $product = $id ? ($this->products[$id] ??= Product::query()->find($id)) : null;

        if ($required && ! $product) {
            throw ValidationException::withMessages(['interventions' => __('Manca il servizio per :extra: impostalo in Impostazioni interventi.', ['extra' => self::extraLabel($extra)])]);
        }

        return $product;
    }

    /**
     * " (oltre a 2 h del monte ore CTR-2026-0001)" when part of the time came from a contract.
     */
    private function contractNote(Intervention $intervention): string
    {
        if ($intervention->contract_minutes <= 0) {
            return '';
        }

        $intervention->loadMissing('contract:id,number');

        return ' '.__('(oltre a :hours scalate dal monte ore :number)', ['hours' => self::hoursLabel($intervention->contract_minutes), 'number' => $intervention->contract?->number]);
    }

    private function hoursTitle(Intervention $intervention): string
    {
        return $intervention->category->name.' '.mb_strtolower($intervention->mode->label());
    }

    public static function extraLabel(string $extra): string
    {
        return match ($extra) {
            'callout' => __('il diritto di uscita'),
            'travel' => __('il tempo di viaggio'),
            default => __('il rimborso chilometrico'),
        };
    }

    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 3);
    }

    /**
     * "1,5 h", "45 min".
     */
    public static function hoursLabel(int $minutes): string
    {
        return $minutes < 60 && $minutes % 60 !== 0 ? "{$minutes} min" : self::number(round($minutes / 60, 2)).' h';
    }

    /**
     * "ottobre 2026", "04/11/2026", "dal 28/10/2026 al 04/11/2026".
     *
     * @param  Collection<int, Intervention>  $interventions
     */
    public static function period(Collection $interventions): string
    {
        $first = $interventions->min('started_at')->copy()->setTimezone(config('app.display_timezone'));
        $last = $interventions->max('started_at')->copy()->setTimezone(config('app.display_timezone'));

        if ($first->isSameDay($last)) {
            return $first->format('d/m/Y');
        }

        if ($first->isSameMonth($last)) {
            return $first->locale('it')->translatedFormat('F Y');
        }

        return __('dal :from al :to', ['from' => $first->format('d/m/Y'), 'to' => $last->format('d/m/Y')]);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
