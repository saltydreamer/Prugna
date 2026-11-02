<script setup>
import { formatMoney } from '@/utils/format';
import { vatLabel } from '@/utils/quoteTotals';

/**
 * Fiscal totals of an invoice (server InvoiceCalculator or utils/invoiceTotals.js).
 */
defineProps({
    totals: { type: Object, required: true },
    invoice: { type: Object, required: true },
    pensionLabel: { type: String, default: 'Cassa previdenziale' },
});
</script>

<template>
    <v-card>
        <v-card-text>
            <div class="d-flex justify-space-between mb-1">
                <span>Imponibile</span>
                <span>{{ formatMoney(totals.subtotal) }}</span>
            </div>
            <div v-if="totals.pension_amount" class="d-flex justify-space-between mb-1">
                <span>{{ pensionLabel }} {{ invoice.pension_rate }}%</span>
                <span>{{ formatMoney(totals.pension_amount) }}</span>
            </div>
            <div
                v-for="group in totals.vat_summary"
                :key="`${group.rate}-${group.nature}`"
                class="d-flex justify-space-between text-body-2 text-medium-emphasis"
            >
                <span>{{ vatLabel(group) }} su {{ formatMoney(group.taxable) }}</span>
                <span>{{ formatMoney(group.vat) }}</span>
            </div>
            <v-divider class="my-2" />
            <div class="d-flex justify-space-between text-subtitle-1 font-weight-bold">
                <span>Totale documento</span>
                <span>{{ formatMoney(totals.total) }}</span>
            </div>
            <div v-if="totals.withholding_amount" class="d-flex justify-space-between text-body-2">
                <span>Ritenuta d'acconto {{ invoice.withholding_rate }}%</span>
                <span>– {{ formatMoney(totals.withholding_amount) }}</span>
            </div>
            <div v-if="invoice.split_payment && totals.vat_total" class="d-flex justify-space-between text-body-2">
                <span>IVA versata dal cliente (split payment)</span>
                <span>– {{ formatMoney(totals.vat_total) }}</span>
            </div>
            <div class="d-flex justify-space-between text-h6 font-weight-bold text-primary mt-2">
                <span>Netto a pagare</span>
                <span>{{ formatMoney(totals.amount_due) }}</span>
            </div>
            <div v-if="totals.stamp_duty_amount" class="text-caption text-medium-emphasis mt-1">
                Bollo virtuale di {{ formatMoney(totals.stamp_duty_amount) }}: operazioni senza IVA oltre 77,47 €.
            </div>
        </v-card-text>
    </v-card>
</template>
