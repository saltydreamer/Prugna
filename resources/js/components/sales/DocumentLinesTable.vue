<script setup>
import { formatMoney } from '@/utils/format';

/**
 * Read-only lines of an order or invoice. With `progress` shows the invoiced quantity of order lines.
 */
defineProps({
    lines: { type: Array, required: true },
    progress: { type: Boolean, default: false },
});

const columns = (progress) => (progress ? 7 : 6);
const quantity = (value) => Number(value).toLocaleString('it-IT', { maximumFractionDigits: 3 });
</script>

<template>
    <v-table density="comfortable">
        <thead>
            <tr>
                <th>Descrizione</th>
                <th class="text-right">Q.tà</th>
                <th v-if="progress" class="text-right">Fatturato</th>
                <th class="text-right">Prezzo</th>
                <th class="text-right">Sc.</th>
                <th class="text-right">IVA</th>
                <th class="text-right">Importo</th>
            </tr>
        </thead>
        <tbody>
            <template v-for="line in lines" :key="line.id">
                <tr v-if="line.type === 'section'">
                    <td :colspan="columns(progress)" class="font-weight-bold text-primary bg-surface-light">{{ line.description }}</td>
                </tr>
                <tr v-else-if="line.type === 'text'">
                    <td :colspan="columns(progress)" class="text-pre-line text-medium-emphasis">{{ line.description }}</td>
                </tr>
                <tr v-else :class="{ 'text-medium-emphasis font-italic': line.system }">
                    <td class="py-2">
                        <div class="text-pre-line">{{ line.description }}</div>
                        <div v-if="line.code" class="text-caption text-medium-emphasis">{{ line.code }}</div>
                    </td>
                    <td class="text-right text-no-wrap">{{ quantity(line.quantity) }} {{ line.unit }}</td>
                    <td v-if="progress" class="text-right text-no-wrap">
                        <v-chip
                            size="small"
                            variant="tonal"
                            :color="line.quantity_remaining <= 0 ? 'success' : line.quantity_invoiced > 0 ? 'warning' : 'grey'"
                        >
                            {{ quantity(line.quantity_invoiced) }} / {{ quantity(line.quantity) }}
                        </v-chip>
                    </td>
                    <td class="text-right text-no-wrap">{{ formatMoney(line.unit_price) }}</td>
                    <td class="text-right">{{ line.discount_percent ? `${line.discount_percent}%` : '' }}</td>
                    <td class="text-right">{{ line.vat_nature ?? `${line.vat_rate}%` }}</td>
                    <td class="text-right text-no-wrap font-weight-medium">{{ formatMoney(line.total) }}</td>
                </tr>
            </template>
        </tbody>
    </v-table>
</template>
