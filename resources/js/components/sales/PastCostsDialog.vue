<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDate, formatMoney } from '@/utils/format';
import { http } from '@/utils/http';

/**
 * Temporary: paid expenses assigned to an order already invoiced as past costs
 * (in its costs and margin, never charged back to the customer).
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    orderId: { type: Number, required: true },
    orderNumber: { type: String, required: true },
});

const search = ref('');
const from = ref(null);
const to = ref(null);
const items = ref([]);
const selected = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');

const chosen = computed(() => items.value.filter((item) => selected.value.includes(item.id)));
const subtotal = computed(() => chosen.value.reduce((sum, item) => sum + item.subtotal, 0));

let timeout;
let requestId = 0;

async function load() {
    const id = ++requestId;
    loading.value = true;
    error.value = '';

    try {
        const query = new URLSearchParams(
            Object.entries({ search: search.value ?? '', from: from.value ?? '', to: to.value ?? '' }).filter(([, value]) => value),
        );
        const { data } = await http.get(`${route('sales.orders.past-costs.index', props.orderId)}?${query}`);

        if (id === requestId) {
            items.value = data;
            selected.value = selected.value.filter((value) => data.some((item) => item.id === value));
        }
    } catch (exception) {
        error.value = exception.message;
    } finally {
        if (id === requestId) {
            loading.value = false;
        }
    }
}

watch(open, (value) => {
    if (value) {
        selected.value = [];
        load();
    }
});

watch([search, from, to], () => {
    clearTimeout(timeout);
    timeout = setTimeout(load, 300);
});

function confirm() {
    saving.value = true;
    error.value = '';

    router.post(
        route('sales.orders.past-costs.store', props.orderId),
        { expenses: selected.value },
        {
            preserveScroll: true,
            onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Operazione non riuscita.'),
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <v-dialog v-model="open" max-width="900" scrollable>
        <v-card title="Imputa costi pregressi" :subtitle="`Spese già pagate da addebitare all'ordine ${orderNumber}`" :loading="loading">
            <v-card-text>
                <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                    Funzione temporanea per allineare i dati di prima di Prugna: le spese scelte entrano nei costi e nel margine dell'ordine, ma non
                    vengono proposte da fatturare al cliente.
                </v-alert>
                <v-row dense class="mb-2">
                    <v-col cols="12" md="6">
                        <v-text-field
                            v-model="search"
                            label="Cerca fornitore, numero, descrizione"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-text-field v-model="from" type="date" label="Dal" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-text-field v-model="to" type="date" label="Al" clearable hide-details />
                    </v-col>
                </v-row>

                <v-empty-state
                    v-if="!loading && !items.length"
                    icon="mdi-receipt-text-check-outline"
                    title="Nessuna spesa da imputare"
                    text="Qui compaiono le spese pagate non ancora collegate a un ordine (o collegate a questo cliente)."
                    size="48"
                />
                <v-table v-else density="compact">
                    <thead>
                        <tr>
                            <th style="width: 40px" />
                            <th>Data</th>
                            <th>Spesa</th>
                            <th>Categoria</th>
                            <th class="text-right">Imponibile</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id">
                            <td><v-checkbox-btn v-model="selected" :value="item.id" density="compact" /></td>
                            <td class="text-no-wrap">{{ formatDate(item.document_date) }}</td>
                            <td class="py-1">
                                <div class="text-body-2">{{ item.supplier }} · {{ item.label }}</div>
                                <div v-if="item.title" class="text-caption text-medium-emphasis">{{ item.title }}</div>
                            </td>
                            <td class="text-caption">{{ item.category ?? '—' }}</td>
                            <td class="text-right text-no-wrap">{{ formatMoney(item.subtotal) }}</td>
                        </tr>
                    </tbody>
                </v-table>

                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-4">{{ error }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <span v-if="selected.length" class="text-body-2 ml-2">
                    {{ selected.length }} {{ selected.length === 1 ? 'spesa' : 'spese' }} ·
                    <strong>{{ formatMoney(subtotal) }}</strong>
                    + IVA
                </span>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-link-variant" :disabled="!selected.length" :loading="saving" @click="confirm">
                    Imputa all'ordine
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
