<script setup>
import { formatMoney } from '@/utils/format';
import { vatLabel } from '@/utils/quoteTotals';

defineProps({
    totals: { type: Object, required: true },
});
</script>

<template>
    <v-card>
        <v-card-text>
            <div class="d-flex justify-space-between mb-1">
                <span>Imponibile</span>
                <span>{{ formatMoney(totals.subtotal) }}</span>
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
            <div class="d-flex justify-space-between text-h6 font-weight-bold text-primary">
                <span>Totale</span>
                <span>{{ formatMoney(totals.total) }}</span>
            </div>
            <div v-if="totals.optional_total > 0" class="text-caption text-medium-emphasis mt-1">
                + {{ formatMoney(totals.optional_total) }} di voci facoltative non incluse
            </div>
        </v-card-text>
    </v-card>
</template>
