/**
 * Totals of an estimate (mirror of App\Support\Estimates\EstimateCalculator): cost and price by
 * category (cost + markup of the category, or the general one), margin, upfront and monthly fee.
 */
const round2 = (value) => Math.round((value + Number.EPSILON) * 100) / 100;

export function itemCost(item) {
    return round2((Number(item.quantity) || 0) * (Number(item.unit_cost) || 0));
}

export function markupFor(estimate, category) {
    const markups = estimate.category_markups ?? {};
    const value = category ? markups[category] : undefined;

    return value === undefined || value === null || value === '' ? Number(estimate.markup_percent) || 0 : Number(value);
}

export function estimateTotals(estimate) {
    const categories = new Map();

    for (const item of estimate.items ?? []) {
        const key = item.category || '';
        const markup = markupFor(estimate, item.category);
        const current = categories.get(key) ?? { name: item.category || null, markup, cost: 0, price: 0, items: 0 };
        const cost = itemCost(item);
        current.cost += cost;
        current.price += cost * (1 + markup / 100);
        current.items += 1;
        categories.set(key, current);
    }

    const list = [...categories.values()].map((category) => ({ ...category, cost: round2(category.cost), price: round2(category.price) }));
    const cost = round2(list.reduce((sum, category) => sum + category.cost, 0));
    const price = round2(list.reduce((sum, category) => sum + category.price, 0));
    const months = Math.max(1, Number(estimate.months) || 1);
    const upfront = round2(Math.min(Number(estimate.upfront) || 0, price));
    const financed = round2(price - upfront);
    const monthly = round2(financed / months);

    return {
        categories: list,
        cost,
        price,
        margin: round2(price - cost),
        marginPercent: price > 0 ? ((price - cost) / price) * 100 : 0,
        upfront,
        financed,
        months,
        monthly,
        contractTotal: round2(upfront + monthly * months),
    };
}
