<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { http } from '@/utils/http';

const props = defineProps({
    month: { type: String, required: true },
    firstMonth: { type: String, required: true },
    summary: { type: Object, required: true },
    byEndpoint: { type: Array, required: true },
    daily: { type: Array, required: true },
    recent: { type: Array, required: true },
});

const { link } = useInertiaLink();

const euro = (value, digits = 3) =>
    (Number(value) || 0).toLocaleString('it-IT', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: digits });
const monthLabel = (value) => new Date(`${value}-01T12:00:00`).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });

const months = computed(() => {
    const list = [];
    const [startYear, startMonth] = props.firstMonth.split('-').map(Number);
    const now = new Date();
    let year = now.getFullYear();
    let month = now.getMonth() + 1;

    while (year > startYear || (year === startYear && month >= startMonth)) {
        const value = `${year}-${String(month).padStart(2, '0')}`;
        list.push({ value, title: monthLabel(value) });
        month--;

        if (month === 0) {
            month = 12;
            year--;
        }
    }

    return list.length ? list : [{ value: props.month, title: monthLabel(props.month) }];
});

function changeMonth(month) {
    router.get(route('settings.einvoicing.costs'), { month }, { preserveScroll: true });
}

const counters = ref(null);
const countersError = ref('');
const loadingCounters = ref(false);

async function loadCounters() {
    loadingCounters.value = true;
    countersError.value = '';

    try {
        counters.value = (await http.get(`${route('settings.einvoicing.counters')}?month=${props.month}`)).data;
    } catch (exception) {
        countersError.value = exception.message;
    } finally {
        loadingCounters.value = false;
    }
}

const dateTime = (iso) => new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' });
const day = (value) => new Date(`${value}T12:00:00`).toLocaleDateString('it-IT', { weekday: 'short', day: 'numeric', month: 'short' });
</script>

<template>
    <div>
        <PageHeader title="Costi Openapi" subtitle="Ogni chiamata a Openapi, con il prezzo del listino: per tenere sotto controllo la spesa">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('settings.einvoicing.index'))">Fatturazione elettronica</v-btn>
                <v-select
                    :model-value="month"
                    :items="months"
                    density="compact"
                    hide-details
                    style="min-width: 190px"
                    @update:model-value="changeMonth"
                />
            </template>
        </PageHeader>

        <v-row class="mb-2">
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Spesa di {{ monthLabel(month) }}</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ euro(summary.month_total) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Spesa dell'anno</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ euro(summary.year_total) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Fatture inviate (produzione)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ summary.invoices_sent }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Chiamate del mese</v-card-subtitle>
                        <v-card-title class="font-weight-bold">
                            {{ summary.month_calls }}
                            <span v-if="summary.month_failed" class="text-body-2 text-error">({{ summary.month_failed }} fallite)</span>
                        </v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12" lg="7">
                <v-card class="mb-6" title="Per servizio">
                    <v-table v-if="byEndpoint.length">
                        <thead>
                            <tr>
                                <th>Servizio</th>
                                <th class="text-right">Chiamate</th>
                                <th class="text-right">Costo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in byEndpoint" :key="`${row.endpoint}-${row.environment}`">
                                <td class="py-1">
                                    <div class="text-body-2">
                                        {{ row.label ?? row.endpoint }}
                                        <v-chip v-if="row.environment === 'test'" size="x-small" variant="tonal" class="ml-1">prova</v-chip>
                                    </div>
                                    <div class="text-caption text-medium-emphasis">{{ row.endpoint }}</div>
                                </td>
                                <td class="text-right">
                                    {{ row.calls }}
                                    <div v-if="row.failed" class="text-caption text-error">{{ row.failed }} fallite</div>
                                </td>
                                <td class="text-right font-weight-medium">{{ euro(row.total) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessuna chiamata in questo mese.</v-card-text>
                </v-card>

                <v-card title="Ultime chiamate">
                    <v-table v-if="recent.length">
                        <thead>
                            <tr>
                                <th>Quando</th>
                                <th>Servizio</th>
                                <th class="text-right">Esito</th>
                                <th class="text-right">Costo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="call in recent" :key="call.id">
                                <td class="text-no-wrap">{{ dateTime(call.created_at) }}</td>
                                <td class="py-1">
                                    <div class="text-body-2">{{ call.endpoint }}</div>
                                    <div v-if="call.error" class="text-caption text-error text-truncate" style="max-width: 360px" :title="call.error">
                                        {{ call.error }}
                                    </div>
                                </td>
                                <td class="text-right">
                                    <v-chip size="x-small" :color="call.success ? 'success' : 'error'" variant="tonal">
                                        {{ call.http_status ?? '—' }}
                                    </v-chip>
                                </td>
                                <td class="text-right text-no-wrap">{{ euro(call.price) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Ancora nessuna chiamata.</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="5">
                <v-card class="mb-6" title="Per giorno">
                    <v-table v-if="daily.length">
                        <tbody>
                            <tr v-for="row in daily" :key="row.day">
                                <td>{{ day(row.day) }}</td>
                                <td class="text-right text-medium-emphasis">{{ row.calls }} chiamate</td>
                                <td class="text-right font-weight-medium">{{ euro(row.total) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessuna chiamata in questo mese.</v-card-text>
                </v-card>

                <v-card title="Confronto con Openapi" subtitle="I contatori di consumo registrati da Openapi per questo mese">
                    <v-card-text>
                        <div class="text-caption text-medium-emphasis mb-3">
                            I costi qui sopra sono calcolati dall'app con il listino che hai impostato. Il saldo del conto e le fatture di Openapi
                            restano nella loro console.
                        </div>
                        <v-alert v-if="countersError" type="error" variant="tonal" class="mb-3">{{ countersError }}</v-alert>
                        <pre v-if="counters" class="text-caption" style="white-space: pre-wrap; max-height: 280px; overflow: auto">{{
                            JSON.stringify(counters, null, 2)
                        }}</pre>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn variant="tonal" prepend-icon="mdi-download-outline" :loading="loadingCounters" @click="loadCounters">
                            Leggi i contatori
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>
