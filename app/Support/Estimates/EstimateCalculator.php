<?php

namespace App\Support\Estimates;

use App\Models\Estimate;
use App\Models\EstimateItem;

/**
 * Totals of an estimate: cost and price (cost + markup of the category) by category, margin,
 * upfront payment and the monthly fee over the term. Mirrored in resources/js/utils/estimateTotals.js.
 */
class EstimateCalculator
{
    /**
     * @return array{
     *     categories: list<array{name: string|null, markup: float, cost: float, price: float, items: int}>,
     *     cost: float, price: float, margin: float, margin_percent: float,
     *     upfront: float, financed: float, months: int, monthly: float, contract_total: float
     * }
     */
    public function summarize(Estimate $estimate): array
    {
        $categories = [];

        foreach ($estimate->items as $item) {
            /** @var EstimateItem $item */
            $key = $item->category ?? '';
            $markup = $estimate->markupFor($item->category);
            $categories[$key] ??= ['name' => $item->category, 'markup' => $markup, 'cost' => 0.0, 'price' => 0.0, 'items' => 0];
            $categories[$key]['cost'] += $item->cost();
            $categories[$key]['price'] += $item->cost() * (1 + $markup / 100);
            $categories[$key]['items']++;
        }

        $categories = array_values(array_map(fn (array $category) => [
            ...$category,
            'cost' => round($category['cost'], 2),
            'price' => round($category['price'], 2),
        ], $categories));

        $cost = round(array_sum(array_column($categories, 'cost')), 2);
        $price = round(array_sum(array_column($categories, 'price')), 2);
        $months = max(1, (int) $estimate->months);
        $upfront = round(min((float) $estimate->upfront, $price), 2);
        $financed = round($price - $upfront, 2);
        $monthly = round($financed / $months, 2);

        return [
            'categories' => $categories,
            'cost' => $cost,
            'price' => $price,
            'margin' => round($price - $cost, 2),
            'margin_percent' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0,
            'upfront' => $upfront,
            'financed' => $financed,
            'months' => $months,
            'monthly' => $monthly,
            'contract_total' => round($upfront + $monthly * $months, 2),
        ];
    }
}
