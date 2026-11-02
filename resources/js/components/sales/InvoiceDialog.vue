<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatMoney } from '@/utils/format';
import { lineTotal } from '@/utils/quoteTotals';

/**
 * "Fattura" from an accepted quote (all / deposit) or an order (what is left / chosen lines / deposit).
 * The result is a draft invoice to check and issue.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    /** 'quote' or 'order'. */
    source: { type: String, required: true },
    document: { type: Object, required: true },
    /** Taxable amount still to invoice (VAT excluded). */
    remaining: { type: Number, default: null },
    /** Issued deposits waiting to be deducted. */
    openDeposits: { type: Number, default: 0 },
});

const form = useForm({
    mode: 'full',
    selection: {},
    deposit_by: 'percent',
    percent: 30,
    amount: null,
    deduct_deposits: true,
});

const items = computed(() =>
    (props.document.lines ?? []).filter((line) => line.type === 'item' && (props.source === 'quote' || line.quantity_remaining > 0)),
);

const taxable = computed(() => props.remaining ?? props.document.subtotal ?? 0);

const modes = computed(() => [
    {
        value: 'full',
        icon: 'mdi-receipt-text-check-outline',
        title: props.source === 'order' ? 'Tutto il residuo' : 'Fattura unica',
        subtitle: props.source === 'order' ? `Fattura ${formatMoney(taxable.value)} + IVA ancora da fatturare` : 'Tutte le righe del preventivo',
    },
    ...(props.source === 'order'
        ? [{ value: 'selection', icon: 'mdi-format-list-checks', title: 'Fattura parziale', subtitle: 'Scegli righe e quantità' }]
        : []),
    { value: 'deposit', icon: 'mdi-cash-fast', title: 'Acconto', subtitle: 'Una percentuale o un importo, da detrarre nel saldo' },
]);

const selectionTotal = computed(() =>
    items.value.reduce((sum, line) => sum + lineTotal({ ...line, quantity: Number(form.selection[line.id] || 0) }), 0),
);

const depositPreview = computed(() => {
    const value = form.deposit_by === 'percent' ? (Number(taxable.value) * Number(form.percent || 0)) / 100 : Number(form.amount || 0);

    return Math.round(value * 100) / 100;
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
        form.selection = Object.fromEntries(items.value.map((line) => [line.id, line.quantity_remaining ?? line.quantity]));
    }
});

function submit() {
    const url = props.source === 'order' ? route('sales.orders.invoice', props.document.id) : route('sales.quotes.invoice', props.document.id);

    form.transform((data) => ({
        mode: data.mode,
        selection: data.mode === 'selection' ? data.selection : undefined,
        percent: data.mode === 'deposit' && data.deposit_by === 'percent' ? data.percent : undefined,
        amount: data.mode === 'deposit' && data.deposit_by === 'amount' ? data.amount : undefined,
        deduct_deposits: data.deduct_deposits,
    })).post(url, { onSuccess: () => (open.value = false) });
}
</script>

<template>
    <v-dialog v-model="open" max-width="640" scrollable>
        <v-card title="Crea fattura" subtitle="Verrà preparata una bozza da controllare prima dell'emissione">
            <v-card-text>
                <v-item-group v-model="form.mode" mandatory>
                    <v-row dense>
                        <v-col v-for="mode in modes" :key="mode.value" cols="12" :sm="12 / modes.length">
                            <v-item v-slot="{ isSelected, toggle }" :value="mode.value">
                                <v-card
                                    :color="isSelected ? 'primary' : undefined"
                                    :variant="isSelected ? 'tonal' : 'outlined'"
                                    class="h-100"
                                    @click="toggle"
                                >
                                    <v-card-item :prepend-icon="mode.icon">
                                        <div class="text-subtitle-1 font-weight-medium text-wrap">{{ mode.title }}</div>
                                    </v-card-item>
                                    <v-card-text class="text-caption pt-0">{{ mode.subtitle }}</v-card-text>
                                </v-card>
                            </v-item>
                        </v-col>
                    </v-row>
                </v-item-group>

                <div v-if="form.mode === 'selection'" class="mt-4">
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Riga</th>
                                <th class="text-right">Residuo</th>
                                <th style="width: 120px">Da fatturare</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in items" :key="line.id">
                                <td class="text-body-2">{{ line.description }}</td>
                                <td class="text-right text-no-wrap">{{ line.quantity_remaining }} {{ line.unit }}</td>
                                <td>
                                    <v-text-field
                                        v-model.number="form.selection[line.id]"
                                        type="number"
                                        min="0"
                                        :max="line.quantity_remaining"
                                        step="any"
                                        density="compact"
                                        hide-details
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                    <div class="text-right mt-2 font-weight-medium">Imponibile selezionato: {{ formatMoney(selectionTotal) }}</div>
                </div>

                <div v-if="form.mode === 'deposit'" class="mt-4">
                    <v-btn-toggle v-model="form.deposit_by" mandatory density="compact" color="primary" variant="outlined" class="mb-4">
                        <v-btn value="percent">Percentuale</v-btn>
                        <v-btn value="amount">Importo</v-btn>
                    </v-btn-toggle>
                    <v-text-field
                        v-if="form.deposit_by === 'percent'"
                        v-model.number="form.percent"
                        label="Percentuale dell'imponibile"
                        type="number"
                        min="1"
                        max="100"
                        suffix="%"
                        :error-messages="form.errors.percent"
                    />
                    <v-text-field
                        v-else
                        v-model.number="form.amount"
                        label="Importo imponibile dell'acconto"
                        type="number"
                        step="0.01"
                        prefix="€"
                        :error-messages="form.errors.amount"
                    />
                    <div class="text-body-2 text-medium-emphasis">
                        Acconto di {{ formatMoney(depositPreview) }} + IVA su {{ formatMoney(taxable) }}. Le aliquote IVA sono ripartite in
                        proporzione.
                    </div>
                </div>

                <v-checkbox
                    v-if="openDeposits > 0 && form.mode !== 'deposit'"
                    v-model="form.deduct_deposits"
                    :label="`Detrai gli acconti già fatturati (${openDeposits})`"
                    hide-details
                    class="mt-2"
                />

                <v-alert v-if="form.errors.selection" type="error" variant="tonal" class="mt-4">{{ form.errors.selection }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-receipt-text-plus-outline" :loading="form.processing" @click="submit">
                    Prepara bozza
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
