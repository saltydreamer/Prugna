<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import DocumentImportDialog from '@/components/DocumentImportDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    expenses: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    categories: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const loading = ref(false);
const importDialog = ref(false);
const query = reactive({ search: props.filters.search, category: props.filters.category });

const headers = [
    { title: 'Data', key: 'document_date', sortable: true },
    { title: 'Fornitore', key: 'supplier', sortable: false },
    { title: 'Documento', key: 'number', sortable: false },
    { title: 'Totale', key: 'total', sortable: true, align: 'end' },
    { title: 'Scadenza', key: 'due_date', sortable: true },
    { title: 'Stato', key: 'status', sortable: false },
];

const tabs = [
    { value: 'all', title: 'Tutte' },
    { value: 'unpaid', title: 'Da pagare' },
    { value: 'overdue', title: 'Scadute' },
    { value: 'paid', title: 'Pagate' },
];

function load(params = {}) {
    router.get(
        route('purchases.expenses.index'),
        {
            search: query.search,
            category: query.category ?? undefined,
            status: props.filters.status,
            supplier: props.filters.supplier ?? undefined,
            sort_by: props.filters.sort_by ?? undefined,
            sort_dir: props.filters.sort_dir ?? undefined,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['expenses', 'filters', 'summary'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}

function onOptions({ page, itemsPerPage, sortBy }) {
    const [sort] = sortBy;

    if (
        page !== props.expenses.meta.current_page ||
        itemsPerPage !== props.filters.per_page ||
        (sort && (sort.key !== props.filters.sort_by || sort.order !== props.filters.sort_dir))
    ) {
        load({ page, per_page: itemsPerPage, sort_by: sort?.key, sort_dir: sort?.order });
    }
}

let searchTimeout;
watch(
    () => query.search,
    () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => load({ page: 1 }), 350);
    },
);
watch(
    () => query.category,
    () => load({ page: 1 }),
);

function open(event, { item }) {
    router.visit(route('purchases.expenses.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader title="Spese e scadenze" subtitle="Fatture dei fornitori, ricevute e pagamenti">
            <template v-if="can('expenses.manage')" #actions>
                <v-btn variant="tonal" prepend-icon="mdi-file-upload-outline" @click="importDialog = true">Carica documento</v-btn>
                <v-btn color="primary" prepend-icon="mdi-plus" v-bind="link(route('purchases.expenses.create'))">Nuova spesa</v-btn>
            </template>
        </PageHeader>

        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="3">
                <v-card @click="load({ status: 'unpaid', page: 1 })">
                    <v-card-item>
                        <v-card-subtitle>Da pagare ({{ summary.unpaid_count }})</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(summary.unpaid) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card :color="summary.overdue_count ? 'error' : undefined" variant="tonal" @click="load({ status: 'overdue', page: 1 })">
                    <v-card-item>
                        <v-card-subtitle>Scadute ({{ summary.overdue_count }})</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(summary.overdue) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>In scadenza nei prossimi 30 giorni</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(summary.due_soon) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Spese del mese (imponibile)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(summary.month) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-tabs
                :model-value="filters.status"
                color="primary"
                show-arrows
                @update:model-value="(status) => load({ status, page: 1, sort_by: undefined, sort_dir: undefined })"
            >
                <v-tab v-for="tab in tabs" :key="tab.value" :value="tab.value">{{ tab.title }}</v-tab>
            </v-tabs>
            <v-divider />

            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="8">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per fornitore, numero o descrizione"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col cols="12" md="4">
                        <v-select v-model="query.category" :items="categories" label="Categoria" clearable hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="expenses.data"
                :items-length="expenses.meta.total"
                :page="expenses.meta.current_page"
                :items-per-page="filters.per_page"
                :items-per-page-options="[10, 25, 50, 100]"
                :loading="loading"
                hover
                class="cursor-pointer"
                @update:options="onOptions"
                @click:row="open"
            >
                <template #[`item.document_date`]="{ item }">{{ formatDate(item.document_date) }}</template>
                <template #[`item.supplier`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.supplier?.name ?? '—' }}</div>
                        <div v-if="item.title" class="text-caption text-medium-emphasis">{{ item.title }}</div>
                    </div>
                </template>
                <template #[`item.number`]="{ item }">
                    <div class="text-no-wrap">{{ item.type_label }}</div>
                    <div class="text-caption text-medium-emphasis">{{ item.number }}</div>
                </template>
                <template #[`item.total`]="{ item }">
                    <span class="text-no-wrap font-weight-medium" :class="{ 'text-success': item.type === 'credit_note' }">
                        {{ item.type === 'credit_note' ? '– ' : '' }}{{ formatMoney(item.total) }}
                    </span>
                </template>
                <template #[`item.due_date`]="{ item }">
                    <span :class="{ 'text-error font-weight-bold': item.is_overdue }">{{ formatDate(item.due_date) }}</span>
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip v-if="item.type === 'credit_note'" size="small" variant="tonal">Nota di credito</v-chip>
                    <v-chip v-else-if="item.is_paid" size="small" color="success" variant="tonal" prepend-icon="mdi-check">Pagata</v-chip>
                    <v-chip v-else-if="item.is_overdue" size="small" color="error" variant="tonal" prepend-icon="mdi-alert-circle-outline">
                        Scaduta
                    </v-chip>
                    <v-chip v-else size="small" color="warning" variant="tonal">Da pagare</v-chip>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-receipt-text-outline"
                        title="Nessuna spesa"
                        text="Carica la foto di una ricevuta, il PDF o l'XML di una fattura del fornitore: i dati vengono compilati per te."
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>

        <DocumentImportDialog v-model="importDialog" kind="expense" />
    </div>
</template>
