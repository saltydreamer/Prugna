<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDate, formatMoney } from '@/utils/format';
import { http } from '@/utils/http';

/**
 * Charge back costs (expenses, supplier orders) to the customer: choose the costs and the markup,
 * one invoice line per cost or a single line (one per VAT rate).
 * mode "create": prepares a draft invoice; mode "add": emits the lines for the invoice editor.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, default: null },
    orderId: { type: Number, default: null },
    mode: { type: String, default: 'create' },
    /** Keys of costs already in the invoice being edited. */
    exclude: { type: Array, default: () => [] },
    /** Keys to preselect (e.g. the lines of one expense); otherwise the costs marked "da ribaltare". */
    preselect: { type: Array, default: null },
});

const emit = defineEmits(['add']);

const costs = ref([]);
const selected = ref([]);
const markups = ref({});
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const grouped = ref(false);
const description = ref('Spese sostenute per vostro conto');
const listDetails = ref(true);

const amountOf = (cost) => Math.round(cost.cost * (1 + Number(markups.value[cost.key] || 0) / 100) * 100) / 100;
const chosen = computed(() => costs.value.filter((cost) => selected.value.includes(cost.key)));
const total = computed(() => chosen.value.reduce((sum, cost) => sum + amountOf(cost), 0));
const totalCost = computed(() => chosen.value.reduce((sum, cost) => sum + cost.cost, 0));
const rates = computed(() => new Set(chosen.value.map((cost) => cost.vat_nature ?? cost.vat_rate)).size);
const allSelected = computed({
    get: () => costs.value.length > 0 && selected.value.length === costs.value.length,
    set: (value) => (selected.value = value ? costs.value.map((cost) => cost.key) : []),
});

watch(open, async (value) => {
    if (!value || !props.organizationId) {
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const query = new URLSearchParams({ organization_id: props.organizationId });

        if (props.orderId) {
            query.set('order_id', props.orderId);
        }

        props.exclude.forEach((key) => query.append('exclude[]', key));
        const { data } = await http.get(`${route('sales.invoices.lookup.costs')}?${query}`);

        costs.value = data;
        markups.value = Object.fromEntries(data.map((cost) => [cost.key, cost.markup_percent]));
        selected.value = props.preselect
            ? data.filter((cost) => props.preselect.includes(cost.key)).map((cost) => cost.key)
            : data.filter((cost) => cost.rebill).map((cost) => cost.key);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});

function payload() {
    return {
        organization_id: props.organizationId,
        order_id: props.orderId ?? undefined,
        costs: selected.value,
        markups: Object.fromEntries(selected.value.map((key) => [key, Number(markups.value[key] || 0)])),
        grouped: grouped.value,
        description: description.value,
        list_details: listDetails.value,
    };
}

async function confirm() {
    error.value = '';
    saving.value = true;

    if (props.mode === 'create') {
        router.post(route('sales.invoices.from-costs'), payload(), {
            onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Operazione non riuscita.'),
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        });

        return;
    }

    try {
        const { data } = await http.post(route('sales.invoices.lookup.cost-lines'), payload());
        emit('add', data);
        open.value = false;
    } catch (exception) {
        error.value = Object.values(exception.errors ?? {})[0]?.[0] ?? exception.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <v-dialog v-model="open" max-width="860" scrollable>
        <v-card title="Fattura costi al cliente" subtitle="Spese e acquisti registrati per questo cliente e non ancora addebitati" :loading="loading">
            <v-card-text>
                <v-empty-state
                    v-if="!loading && !costs.length"
                    icon="mdi-cart-check"
                    title="Nessun costo da fatturare"
                    text="Qui compaiono le spese e gli ordini fornitore (inviati) collegati a questo cliente o ordine, non ancora in fattura."
                    size="48"
                />
                <template v-else>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th style="width: 40px"><v-checkbox-btn v-model="allSelected" density="compact" /></th>
                                <th>Costo</th>
                                <th class="text-right">Importo</th>
                                <th style="width: 110px">Ricarico</th>
                                <th class="text-right">Al cliente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cost in costs" :key="cost.key">
                                <td><v-checkbox-btn v-model="selected" :value="cost.key" density="compact" /></td>
                                <td class="py-2">
                                    <div class="text-body-2">{{ cost.description }}</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ cost.source.label }} · {{ cost.source.supplier }} · {{ formatDate(cost.source.date) }}
                                        <v-chip v-if="cost.rebill" size="x-small" color="info" variant="tonal" class="ml-1">da ribaltare</v-chip>
                                    </div>
                                </td>
                                <td class="text-right text-no-wrap">
                                    {{ formatMoney(cost.cost) }}
                                    <div class="text-caption text-medium-emphasis">{{ cost.vat_nature ?? `IVA ${cost.vat_rate}%` }}</div>
                                </td>
                                <td>
                                    <v-text-field
                                        v-model.number="markups[cost.key]"
                                        type="number"
                                        min="0"
                                        step="1"
                                        suffix="%"
                                        density="compact"
                                        hide-details
                                        :disabled="!selected.includes(cost.key)"
                                    />
                                </td>
                                <td class="text-right text-no-wrap font-weight-medium">{{ formatMoney(amountOf(cost)) }}</td>
                            </tr>
                        </tbody>
                    </v-table>

                    <v-btn-toggle v-model="grouped" mandatory density="compact" color="primary" variant="outlined" class="mt-4">
                        <v-btn :value="false" prepend-icon="mdi-format-list-bulleted">Una riga per costo</v-btn>
                        <v-btn :value="true" prepend-icon="mdi-format-align-justify">Voce unica</v-btn>
                    </v-btn-toggle>

                    <div v-if="grouped" class="mt-4">
                        <v-text-field v-model="description" label="Descrizione della voce" maxlength="255" hide-details class="mb-2" />
                        <v-checkbox v-model="listDetails" label="Elenca i costi inclusi nella descrizione" density="compact" hide-details />
                        <div v-if="rates > 1" class="text-caption text-medium-emphasis">
                            I costi hanno aliquote IVA diverse: verrà creata una voce per ciascuna aliquota.
                        </div>
                    </div>

                    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-4">{{ error }}</v-alert>
                </template>
            </v-card-text>
            <v-card-actions>
                <span v-if="selected.length" class="text-body-2 ml-2">
                    {{ selected.length }} costi · {{ formatMoney(totalCost) }} →
                    <strong>{{ formatMoney(total) }}</strong>
                    + IVA
                </span>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    :prepend-icon="mode === 'create' ? 'mdi-receipt-text-plus-outline' : 'mdi-plus'"
                    :disabled="!selected.length"
                    :loading="saving"
                    @click="confirm"
                >
                    {{ mode === 'create' ? 'Prepara bozza di fattura' : 'Aggiungi alla fattura' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
