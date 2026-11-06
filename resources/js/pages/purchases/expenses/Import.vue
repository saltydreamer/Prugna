<script setup>
import { computed, watch } from 'vue';
import { router, usePoll } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';

const props = defineProps({
    /** { id, created_at, started, completed, counts: {status: n}, total } */
    batch: { type: Object, required: true },
    items: { type: Array, required: true },
});

const { link } = useInertiaLink();

const STATUSES = {
    pending: { label: 'In coda', color: 'grey' },
    processing: { label: 'In lettura', color: 'info' },
    recorded: { label: 'Registrata', color: 'success' },
    duplicate: { label: 'Già registrata', color: 'secondary' },
    failed: { label: 'Da completare', color: 'error' },
};

const count = (status) => props.batch.counts[status] ?? 0;
const done = computed(() => props.items.length - count('pending') - count('processing'));
const running = computed(() => props.batch.started && !props.batch.completed);

// The page follows the reading until every document is done.
const { start, stop } = usePoll(3000, { only: ['batch', 'items'] }, { autoStart: running.value });
watch(running, (value) => (value ? start() : stop()));

function startReading() {
    router.post(route('purchases.expenses.imports.start', props.batch.id));
}

function retry(item) {
    router.post(route('purchases.expenses.imports.retry', [props.batch.id, item.id]), {}, { preserveScroll: true, onSuccess: () => start() });
}
</script>

<template>
    <div>
        <PageHeader title="Spese dai documenti" :subtitle="`Caricati il ${formatDateTime(batch.created_at)}`">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('purchases.expenses.index'))">Spese</v-btn>
            </template>
        </PageHeader>

        <v-alert v-if="!batch.started" type="warning" variant="tonal" class="mb-6">
            Il caricamento si è interrotto prima della lettura: {{ items.length }} documenti sono caricati ma non ancora letti.
            <template #append>
                <v-btn color="warning" variant="flat" prepend-icon="mdi-text-recognition" @click="startReading">Leggi i documenti</v-btn>
            </template>
        </v-alert>

        <v-card class="mb-6">
            <v-card-text>
                <template v-if="running">
                    <div class="d-flex align-center justify-space-between mb-2">
                        <span class="text-body-1 font-weight-medium">Lettura in corso: {{ done }} di {{ items.length }}</span>
                        <span class="text-caption text-medium-emphasis">Puoi chiudere la pagina: ti avviso con una notifica quando ha finito.</span>
                    </div>
                    <v-progress-linear :model-value="items.length ? (done / items.length) * 100 : 0" color="primary" height="8" rounded />
                </template>
                <div class="d-flex flex-wrap ga-2" :class="{ 'mt-4': running }">
                    <v-chip color="success" variant="tonal" prepend-icon="mdi-check">
                        {{ count('recorded') }} registrate · {{ formatMoney(batch.total) }}
                    </v-chip>
                    <v-chip v-if="count('duplicate')" color="secondary" variant="tonal" prepend-icon="mdi-content-duplicate">
                        {{ count('duplicate') }} già registrate
                    </v-chip>
                    <v-chip v-if="count('failed')" color="error" variant="tonal" prepend-icon="mdi-alert-circle-outline">
                        {{ count('failed') }} da completare a mano
                    </v-chip>
                </div>
            </v-card-text>
        </v-card>

        <v-card>
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Esito</th>
                        <th>Spesa</th>
                        <th class="text-end">Totale</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.id">
                        <td class="py-3" style="max-width: 260px">
                            <div class="font-weight-medium text-truncate" :title="item.name">{{ item.name }}</div>
                            <a v-if="item.file_url" :href="item.file_url" target="_blank" class="text-caption">Apri l'originale</a>
                        </td>
                        <td class="py-3">
                            <v-chip size="small" :color="STATUSES[item.status].color" variant="tonal">
                                <v-progress-circular v-if="item.status === 'processing'" indeterminate size="12" width="2" class="mr-1" />
                                {{ STATUSES[item.status].label }}
                            </v-chip>
                        </td>
                        <td class="py-3">
                            <template v-if="item.supplier">
                                <span class="font-weight-medium">{{ item.supplier.name }}</span>
                                <v-chip v-if="item.supplier_source === 'created'" size="x-small" color="info" variant="tonal" class="ml-1">
                                    nuovo
                                </v-chip>
                                <v-chip v-else-if="item.supplier_source === 'generic'" size="x-small" color="warning" variant="tonal" class="ml-1">
                                    da assegnare
                                </v-chip>
                            </template>
                            <div v-if="item.expense" class="text-caption text-medium-emphasis">
                                {{ item.expense.type_label }} {{ item.expense.number }} del {{ formatDate(item.expense.document_date) }}
                                <template v-if="item.expense.paid">· pagata</template>
                            </div>
                            <div v-if="item.error" class="text-caption text-error">{{ item.error }}</div>
                            <ul v-if="item.warnings.length" class="text-caption text-warning pl-4 mt-1">
                                <li v-for="(warning, index) in item.warnings" :key="index">{{ warning }}</li>
                            </ul>
                        </td>
                        <td class="py-3 text-end text-no-wrap">{{ item.expense ? formatMoney(item.expense.total) : '' }}</td>
                        <td class="py-3 text-end text-no-wrap">
                            <v-btn
                                v-if="item.expense"
                                size="small"
                                variant="text"
                                append-icon="mdi-chevron-right"
                                v-bind="link(route('purchases.expenses.show', item.expense.id))"
                            >
                                Apri spesa
                            </v-btn>
                            <template v-if="item.status === 'failed'">
                                <v-btn
                                    v-if="item.extraction_id"
                                    size="small"
                                    variant="tonal"
                                    v-bind="link(route('purchases.expenses.create', { extraction: item.extraction_id }))"
                                >
                                    Completa a mano
                                </v-btn>
                                <v-btn size="small" variant="text" prepend-icon="mdi-refresh" @click="retry(item)">Riprova</v-btn>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>
    </div>
</template>
