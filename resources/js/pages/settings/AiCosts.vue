<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import ColumnChart from '@/components/charts/ColumnChart.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { http } from '@/utils/http';

const props = defineProps({
    month: { type: String, required: true },
    firstMonth: { type: String, required: true },
    /** { month_total, month_calls, month_failed, average, previous_total, year_total } */
    summary: { type: Object, required: true },
    /** The 12 months up to the chosen one: [{ month, total, calls }] */
    months: { type: Array, required: true },
    byFeature: { type: Array, required: true },
    byModel: { type: Array, required: true },
    byUser: { type: Array, required: true },
    recent: { type: Array, required: true },
});

const { link } = useInertiaLink();

/** OpenRouter charges in US dollars. */
const usd = (value, digits = 4) =>
    (Number(value) || 0).toLocaleString('it-IT', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: digits });
const monthLabel = (value) => new Date(`${value}-01T12:00:00`).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
const shortMonth = (value) => new Date(`${value}-01T12:00:00`).toLocaleDateString('it-IT', { month: 'short' });
const dateTime = (iso) => new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' });
const seconds = (ms) => (ms ? `${(ms / 1000).toLocaleString('it-IT', { maximumFractionDigits: 1 })} s` : '');

const monthOptions = computed(() => {
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
    router.get(route('settings.ai-costs.index'), { month }, { preserveScroll: true });
}

const trend = computed(() => {
    if (!props.summary.previous_total) {
        return null;
    }

    const change = ((props.summary.month_total - props.summary.previous_total) / props.summary.previous_total) * 100;

    return `${change >= 0 ? '+' : ''}${change.toLocaleString('it-IT', { maximumFractionDigits: 0 })}% sul mese prima`;
});

const chartTable = ref(false);

// --- Comparison with OpenRouter -------------------------------------------------------------
const key = ref(null);
const keyError = ref('');
const loadingKey = ref(false);

async function loadKey() {
    loadingKey.value = true;
    keyError.value = '';

    try {
        key.value = await http.get(route('settings.ai-costs.key'));
    } catch (exception) {
        keyError.value = exception.message;
    } finally {
        loadingKey.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader title="Costi IA" subtitle="Ogni chiamata a OpenRouter con quello che è costata: lettura dei documenti e Preventivatore">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-cog-outline" v-bind="link(route('settings.index', { tab: 'ai' }))">Impostazioni AI</v-btn>
                <v-select
                    :model-value="month"
                    :items="monthOptions"
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
                        <v-card-title class="font-weight-bold">{{ usd(summary.month_total) }}</v-card-title>
                        <div v-if="trend" class="text-caption text-medium-emphasis">{{ trend }}</div>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Spesa dell'anno</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ usd(summary.year_total) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Costo medio di una chiamata</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ usd(summary.average, 5) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Chiamate del mese</v-card-subtitle>
                        <v-card-title class="font-weight-bold">
                            {{ summary.month_calls }}
                            <span v-if="summary.month_failed" class="text-body-2 text-error">({{ summary.month_failed }} non riuscite)</span>
                        </v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-card class="mb-6" title="Ultimi dodici mesi">
            <template #append>
                <v-btn size="small" variant="text" :prepend-icon="chartTable ? 'mdi-chart-bar' : 'mdi-table'" @click="chartTable = !chartTable">
                    {{ chartTable ? 'Grafico' : 'Tabella' }}
                </v-btn>
            </template>
            <v-card-text>
                <ColumnChart
                    v-if="!chartTable"
                    label="Costi IA per mese"
                    :categories="months.map((item) => shortMonth(item.month))"
                    :series="[{ name: 'Costo', values: months.map((item) => item.total) }]"
                    :highlight="months.length - 1"
                    :format="(value) => usd(value)"
                    :format-tick="(value) => usd(value, 2)"
                    :height="200"
                    selectable
                    @select="(index) => changeMonth(months[index].month)"
                />
                <v-table v-else density="compact">
                    <thead>
                        <tr>
                            <th>Mese</th>
                            <th class="text-right">Chiamate</th>
                            <th class="text-right">Costo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in [...months].reverse()" :key="item.month">
                            <td>{{ monthLabel(item.month) }}</td>
                            <td class="text-right">{{ item.calls }}</td>
                            <td class="text-right font-weight-medium">{{ usd(item.total) }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
        </v-card>

        <v-row>
            <v-col cols="12" lg="7">
                <v-card class="mb-6" title="Per funzione" :subtitle="monthLabel(month)">
                    <v-table v-if="byFeature.length">
                        <thead>
                            <tr>
                                <th>Funzione</th>
                                <th class="text-right">Chiamate</th>
                                <th class="text-right">Costo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in byFeature" :key="row.feature">
                                <td>{{ row.label }}</td>
                                <td class="text-right">{{ row.calls }}</td>
                                <td class="text-right font-weight-medium">{{ usd(row.total) }}</td>
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
                                <th>Funzione</th>
                                <th class="text-right">Costo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="call in recent" :key="call.id">
                                <td class="text-no-wrap py-1">
                                    {{ dateTime(call.created_at) }}
                                    <div class="text-caption text-medium-emphasis">{{ call.user ?? 'Sistema' }}</div>
                                </td>
                                <td class="py-1">
                                    <div class="text-body-2">
                                        {{ call.feature }}
                                        <v-chip v-if="!call.success" size="x-small" color="error" variant="tonal" class="ml-1">non riuscita</v-chip>
                                    </div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ call.model }}
                                        <template v-if="call.tokens">· {{ call.tokens.toLocaleString('it-IT') }} token</template>
                                        <template v-if="call.duration_ms">· {{ seconds(call.duration_ms) }}</template>
                                    </div>
                                    <div v-if="call.error" class="text-caption text-error text-truncate" style="max-width: 420px" :title="call.error">
                                        {{ call.error }}
                                    </div>
                                </td>
                                <td class="text-right text-no-wrap">{{ call.cost === null ? '—' : usd(call.cost, 5) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Ancora nessuna chiamata.</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="5">
                <v-card class="mb-6" title="Per modello" :subtitle="monthLabel(month)">
                    <v-table v-if="byModel.length">
                        <tbody>
                            <tr v-for="row in byModel" :key="row.model">
                                <td class="py-1">
                                    <div class="text-body-2">{{ row.model }}</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ row.calls }} chiamate · {{ row.tokens.toLocaleString('it-IT') }} token
                                    </div>
                                </td>
                                <td class="text-right font-weight-medium">{{ usd(row.total) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessuna chiamata in questo mese.</v-card-text>
                </v-card>

                <v-card class="mb-6" title="Per utente" :subtitle="monthLabel(month)">
                    <v-table v-if="byUser.length">
                        <tbody>
                            <tr v-for="row in byUser" :key="row.name">
                                <td>{{ row.name }}</td>
                                <td class="text-right text-medium-emphasis">{{ row.calls }} chiamate</td>
                                <td class="text-right font-weight-medium">{{ usd(row.total) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessuna chiamata in questo mese.</v-card-text>
                </v-card>

                <v-card title="Confronto con OpenRouter" subtitle="Quanto ha consumato la chiave API secondo OpenRouter">
                    <v-card-text>
                        <div class="text-caption text-medium-emphasis mb-3">
                            I costi qui sopra sono quelli che OpenRouter restituisce a ogni chiamata, in dollari. Il credito e le ricevute restano
                            nella console di OpenRouter.
                        </div>
                        <v-alert v-if="keyError" type="error" variant="tonal" class="mb-3">{{ keyError }}</v-alert>
                        <v-list v-if="key" density="compact" class="pa-0">
                            <v-list-item v-if="key.label" title="Chiave" :subtitle="key.label" />
                            <v-list-item title="Consumo totale della chiave" :subtitle="key.usage === null ? '—' : usd(key.usage)" />
                            <v-list-item v-if="key.limit !== null" title="Limite di spesa" :subtitle="usd(key.limit, 2)" />
                            <v-list-item v-if="key.limit_remaining !== null" title="Ancora disponibile" :subtitle="usd(key.limit_remaining)" />
                        </v-list>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn variant="tonal" prepend-icon="mdi-download-outline" :loading="loadingKey" @click="loadKey">Leggi da OpenRouter</v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>
