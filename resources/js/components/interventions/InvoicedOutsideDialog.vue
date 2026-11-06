<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate } from '@/utils/format';
import { formatHours } from '@/utils/duration';
import { http } from '@/utils/http';

/**
 * «Segna come già fatturati»: interventions to invoice up to a day that were invoiced outside Prugna
 * (e.g. in Dolibarr). Interventions listing jobs after that day start unchecked: split them first.
 */
const open = defineModel({ type: Boolean, default: false });

const { link } = useInertiaLink();

const lastYearEnd = () => `${new Date().getFullYear() - 1}-12-31`;

const until = ref(lastYearEnd());
const items = ref([]);
const selected = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');

const chosen = computed(() => items.value.filter((item) => selected.value.includes(item.id)));
const minutes = computed(() => chosen.value.reduce((sum, item) => sum + item.minutes, 0));
const straddling = computed(() => items.value.filter((item) => item.later_jobs > 0));
const allSelected = computed({
    get: () => items.value.length > 0 && selected.value.length === items.value.length,
    set: (value) => (selected.value = value ? items.value.map((item) => item.id) : []),
});

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const { data } = await http.get(`${route('interventions.invoiced-outside.index')}?${new URLSearchParams({ until: until.value })}`);
        items.value = data;
        selected.value = data.filter((item) => !item.later_jobs).map((item) => item.id);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => {
    if (value) {
        until.value = lastYearEnd();
        load();
    }
});

watch(until, () => open.value && until.value && load());

function confirm() {
    saving.value = true;
    error.value = '';

    router.post(
        route('interventions.invoiced-outside.store'),
        { until: until.value, interventions: selected.value },
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
        <v-card title="Segna come già fatturati" subtitle="Interventi fatturati fuori da Prugna (ad esempio in Dolibarr)" :loading="loading">
            <v-card-text>
                <v-row dense align="center" class="mb-2">
                    <v-col cols="12" sm="5">
                        <v-text-field v-model="until" type="date" label="Interventi fino al" />
                    </v-col>
                    <v-col cols="12" sm="7" class="text-body-2 text-medium-emphasis">
                        Gli interventi segnati risultano fatturati: non si modificano e non vengono più proposti in fattura. Il segno si toglie dalla
                        pagina dell'intervento.
                    </v-col>
                </v-row>

                <v-alert v-if="straddling.length" type="warning" variant="tonal" density="compact" class="mb-3">
                    {{ straddling.length === 1 ? 'Un intervento elenca' : `${straddling.length} interventi elencano` }} anche lavori dopo il
                    {{ formatDate(until) }}: dividili prima, così segni solo i lavori fino a quella data. Sono esclusi, ma puoi spuntarli per segnarli
                    per intero.
                </v-alert>

                <v-empty-state
                    v-if="!loading && !items.length"
                    icon="mdi-timer-check-outline"
                    title="Nessun intervento da fatturare"
                    text="Non ci sono interventi da fatturare iniziati entro questa data."
                    size="48"
                />

                <v-table v-else density="compact">
                    <thead>
                        <tr>
                            <th style="width: 40px"><v-checkbox-btn v-model="allSelected" density="compact" /></th>
                            <th>Intervento</th>
                            <th>Cliente</th>
                            <th class="text-right">Ore</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id">
                            <td><v-checkbox-btn v-model="selected" :value="item.id" density="compact" /></td>
                            <td class="py-1">
                                <div class="text-body-2">
                                    <strong>{{ formatDate(item.started_at) }}</strong>
                                    ·
                                    <a v-bind="link(route('interventions.show', item.id))" target="_blank">{{ item.number }}</a>
                                </div>
                                <div class="text-caption text-medium-emphasis text-truncate" style="max-width: 420px">{{ item.description }}</div>
                                <v-chip v-if="item.later_jobs" size="x-small" color="warning" variant="tonal" class="mt-1">
                                    {{ item.later_jobs }} lavori dopo il {{ formatDate(until) }}: da dividere
                                </v-chip>
                            </td>
                            <td>{{ item.organization }}</td>
                            <td class="text-right text-no-wrap">{{ formatHours(item.minutes) }}</td>
                        </tr>
                    </tbody>
                </v-table>

                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-4">{{ error }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <span v-if="selected.length" class="text-body-2 ml-2">
                    {{ selected.length }} interventi ·
                    <strong>{{ formatHours(minutes) }}</strong>
                </span>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-receipt-text-check-outline"
                    :disabled="!selected.length || loading"
                    :loading="saving"
                    @click="confirm"
                >
                    Segna come fatturati
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
