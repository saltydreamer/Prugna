<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDate, formatMoney } from '@/utils/format';
import { formatHours, formatMinutes } from '@/utils/duration';
import { http } from '@/utils/http';

/**
 * "Fattura interventi": choose the interventions of a customer still to invoice,
 * one set of lines per intervention or a single line per category and mode (extras on their own lines).
 * mode "create": prepares a draft invoice; mode "add": emits the lines for the invoice editor.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, default: null },
    orderId: { type: Number, default: null },
    mode: { type: String, default: 'create' },
    /** Ids already in the invoice being edited. */
    exclude: { type: Array, default: () => [] },
    /** Ids to preselect; otherwise all. */
    preselect: { type: Array, default: null },
});

const emit = defineEmits(['add']);

const interventions = ref([]);
const selected = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const grouped = ref(false);
const listDetails = ref(true);

const chosen = computed(() => interventions.value.filter((item) => selected.value.includes(item.id)));
const total = computed(() => chosen.value.reduce((sum, item) => sum + (item.amount ?? 0), 0));
const minutes = computed(() => chosen.value.reduce((sum, item) => sum + item.invoiceable_minutes, 0));
const problems = computed(() => [...new Set(chosen.value.map((item) => item.problem).filter(Boolean))]);
const allSelected = computed({
    get: () => interventions.value.length > 0 && selected.value.length === interventions.value.length,
    set: (value) => (selected.value = value ? interventions.value.map((item) => item.id) : []),
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

        props.exclude.forEach((id) => query.append('exclude[]', id));
        const { data } = await http.get(`${route('sales.invoices.lookup.interventions')}?${query}`);

        interventions.value = data;
        selected.value = props.preselect
            ? data.filter((item) => props.preselect.includes(item.id)).map((item) => item.id)
            : data.map((item) => item.id);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});

function extras(item) {
    return [
        item.callout ? 'uscita' : null,
        item.travel_minutes ? `viaggio ${formatMinutes(item.travel_minutes)}` : null,
        item.km ? `${item.km.toLocaleString('it-IT')} km` : null,
        item.materials_count ? `${item.materials_count} materiali` : null,
    ].filter(Boolean);
}

function payload() {
    return {
        organization_id: props.organizationId,
        order_id: props.orderId ?? undefined,
        interventions: selected.value,
        grouped: grouped.value,
        list_details: listDetails.value,
    };
}

function firstError(errors) {
    return [Object.values(errors ?? {})[0]].flat()[0];
}

async function confirm() {
    error.value = '';
    saving.value = true;

    if (props.mode === 'create') {
        router.post(route('sales.invoices.from-interventions'), payload(), {
            onError: (errors) => (error.value = firstError(errors) ?? 'Operazione non riuscita.'),
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        });

        return;
    }

    try {
        const { data } = await http.post(route('sales.invoices.lookup.intervention-lines'), payload());
        emit('add', data);
        open.value = false;
    } catch (exception) {
        error.value = firstError(exception.errors) ?? exception.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <v-dialog v-model="open" max-width="900" scrollable>
        <v-card title="Fattura interventi" subtitle="Interventi terminati e fatturabili, non ancora in fattura" :loading="loading">
            <v-card-text>
                <v-empty-state
                    v-if="!loading && !interventions.length"
                    icon="mdi-timer-check-outline"
                    title="Nessun intervento da fatturare"
                    text="Qui compaiono gli interventi terminati e fatturabili di questo cliente (o ordine) non ancora in fattura."
                    size="48"
                />
                <template v-else>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th style="width: 40px"><v-checkbox-btn v-model="allSelected" density="compact" /></th>
                                <th>Intervento</th>
                                <th class="text-right">Ore</th>
                                <th class="text-right">Importo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in interventions" :key="item.id">
                                <td><v-checkbox-btn v-model="selected" :value="item.id" density="compact" /></td>
                                <td class="py-2">
                                    <div class="text-body-2">
                                        <strong>{{ formatDate(item.started_at) }}</strong>
                                        · {{ item.category }} {{ item.mode_label.toLowerCase() }}
                                        <span class="text-medium-emphasis">· {{ item.number }}</span>
                                    </div>
                                    <div v-if="item.description" class="text-caption">{{ item.description }}</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ [item.technician, item.order?.number, ...extras(item)].filter(Boolean).join(' · ') }}
                                    </div>
                                    <div v-if="item.problem" class="text-caption text-error">{{ item.problem }}</div>
                                </td>
                                <td class="text-right text-no-wrap">
                                    {{ formatHours(item.invoiceable_minutes) }}
                                    <div v-if="item.duration_minutes !== item.invoiceable_minutes" class="text-caption text-medium-emphasis">
                                        effettive {{ formatMinutes(item.duration_minutes) }}
                                    </div>
                                    <div v-if="item.contract_minutes" class="text-caption text-medium-emphasis">
                                        {{ formatHours(item.contract_minutes) }} dal monte ore
                                    </div>
                                </td>
                                <td class="text-right text-no-wrap font-weight-medium">
                                    {{ item.amount === null ? '—' : formatMoney(item.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </v-table>

                    <v-btn-toggle v-model="grouped" mandatory density="compact" color="primary" variant="outlined" class="mt-4">
                        <v-btn :value="false" prepend-icon="mdi-format-list-bulleted">Una riga per intervento</v-btn>
                        <v-btn :value="true" prepend-icon="mdi-format-align-justify">Voce unica per categoria</v-btn>
                    </v-btn-toggle>

                    <div v-if="grouped" class="mt-2">
                        <v-checkbox v-model="listDetails" label="Elenca gli interventi inclusi nella descrizione" density="compact" hide-details />
                        <div class="text-caption text-medium-emphasis">
                            Es. «Assistenza da remoto, ottobre 2026 (7,5 h)». Uscite, km, viaggio e materiali restano su righe proprie.
                        </div>
                    </div>

                    <v-alert v-for="problem in problems" :key="problem" type="warning" variant="tonal" density="compact" class="mt-4">
                        {{ problem }}
                    </v-alert>
                    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-4">{{ error }}</v-alert>
                </template>
            </v-card-text>
            <v-card-actions>
                <span v-if="selected.length" class="text-body-2 ml-2">
                    {{ selected.length }} interventi · {{ formatHours(minutes) }} ·
                    <strong>{{ formatMoney(total) }}</strong>
                    + IVA
                </span>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    :prepend-icon="mode === 'create' ? 'mdi-receipt-text-plus-outline' : 'mdi-plus'"
                    :disabled="!selected.length || problems.length > 0"
                    :loading="saving"
                    @click="confirm"
                >
                    {{ mode === 'create' ? 'Prepara bozza di fattura' : 'Aggiungi alla fattura' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
