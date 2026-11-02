/**
 * Mirror of App\Actions\Invoices\InvoiceCalculator for the live invoice editor:
 * pension fund on the taxable (stamp duty excluded) added to its VAT group, withholding tax,
 * split payment and virtual stamp duty estimate.
 */
import { lineTotal } from './quoteTotals';

const round2 = (value) => Math.round((value + Number.EPSILON) * 100) / 100;

const STAMP_DUTY_NATURES = ['N1', 'N2', 'N2.1', 'N2.2', 'N3', 'N3.1', 'N3.2', 'N3.3', 'N3.4', 'N3.5', 'N3.6', 'N4'];
export const STAMP_DUTY_THRESHOLD = 77.47;
export const STAMP_DUTY_AMOUNT = 2;

/**
 * @param {Array} lines  { type, quantity, unit_price, discount_percent, vat_rate, vat_nature, system }
 * @param {Object} fiscal  { apply_pension, pension_rate, pension_vat_rate, pension_vat_nature, apply_withholding,
 *                          withholding_rate, pension_withholding, split_payment, stamp_duty_enabled, stamp_duty_charge_customer }
 */
export function invoiceTotals(lines, fiscal) {
    const groups = new Map();
    const add = (rate, nature, amount) => {
        const key = `${rate}|${nature ?? ''}`;
        const group = groups.get(key) ?? { rate, nature: nature ?? null, taxable: 0, vat: 0 };
        group.taxable += amount;
        groups.set(key, group);
    };

    let base = 0;
    let exempt = 0;

    for (const line of lines) {
        if (line.type !== 'item' || line.system === 'stamp_duty') {
            continue;
        }

        const total = lineTotal(line);
        base += total;
        add(Number(line.vat_rate || 0), line.vat_nature, total);

        if (STAMP_DUTY_NATURES.includes(line.vat_nature)) {
            exempt += total;
        }
    }

    const pension = fiscal.apply_pension ? round2((base * Number(fiscal.pension_rate || 0)) / 100) : 0;

    if (pension) {
        add(Number(fiscal.pension_vat_rate || 0), fiscal.pension_vat_nature, pension);

        if (STAMP_DUTY_NATURES.includes(fiscal.pension_vat_nature)) {
            exempt += pension;
        }
    }

    const stampDuty = fiscal.stamp_duty_enabled && round2(exempt) > STAMP_DUTY_THRESHOLD ? STAMP_DUTY_AMOUNT : 0;
    let subtotal = base;

    if (stampDuty && fiscal.stamp_duty_charge_customer) {
        subtotal += stampDuty;
        add(0, 'N1', stampDuty);
    }

    const summary = [...groups.values()]
        .map((group) => ({ ...group, taxable: round2(group.taxable), vat: round2((round2(group.taxable) * group.rate) / 100) }))
        .sort((a, b) => b.rate - a.rate);

    subtotal = round2(subtotal);
    const vatTotal = round2(summary.reduce((sum, group) => sum + group.vat, 0));
    const total = round2(subtotal + pension + vatTotal);
    const withholdingBase = base + (fiscal.pension_withholding ? pension : 0);
    const withholding = fiscal.apply_withholding ? round2((withholdingBase * Number(fiscal.withholding_rate || 0)) / 100) : 0;

    return {
        subtotal,
        pension_amount: pension,
        vat_total: vatTotal,
        total,
        withholding_amount: withholding,
        amount_due: round2(total - withholding - (fiscal.split_payment ? vatTotal : 0)),
        stamp_duty_amount: stampDuty,
        vat_summary: summary,
    };
}
