/**
 * Mirror of App\Actions\Quotes\QuoteTotals for live totals in the editor and the public page:
 * lines rounded to the cent, VAT computed on the summary per rate, optional lines only when selected.
 */
const round2 = (value) => Math.round((value + Number.EPSILON) * 100) / 100;

export function lineTotal(line) {
    return round2(Number(line.quantity || 0) * Number(line.unit_price || 0) * (1 - Number(line.discount_percent || 0) / 100));
}

/**
 * @param {Array} lines  quote lines ({ type, quantity, unit_price, discount_percent, vat_rate, vat_nature, is_optional, is_selected })
 */
export function quoteTotals(lines) {
    const groups = new Map();
    let optionalTotal = 0;

    for (const line of lines) {
        if (line.type !== 'item') {
            continue;
        }

        const total = lineTotal(line);

        if (line.is_optional && !line.is_selected) {
            optionalTotal += total;
            continue;
        }

        const rate = Number(line.vat_rate || 0);
        const key = `${rate}|${line.vat_nature ?? ''}`;
        const group = groups.get(key) ?? { rate, nature: line.vat_nature ?? null, taxable: 0, vat: 0 };
        group.taxable += total;
        groups.set(key, group);
    }

    const summary = [...groups.values()]
        .map((group) => ({ ...group, taxable: round2(group.taxable), vat: round2((round2(group.taxable) * group.rate) / 100) }))
        .sort((a, b) => b.rate - a.rate);

    const subtotal = round2(summary.reduce((sum, group) => sum + group.taxable, 0));
    const vatTotal = round2(summary.reduce((sum, group) => sum + group.vat, 0));

    return { subtotal, vat_total: vatTotal, total: round2(subtotal + vatTotal), optional_total: round2(optionalTotal), vat_summary: summary };
}

export function vatLabel(group) {
    return group.nature ? `Natura ${group.nature}` : `IVA ${group.rate}%`;
}
