<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    quotes: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    counts: { type: Object, required: true },
    owners: { type: Array, default: null },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const loading = ref(false);
const query = reactive({ search: props.filters.search, owner: props.filters.owner });

const headers = computed(() => [
    { title: 'Numero', key: 'number', sortable: true },
    { title: 'Cliente', key: 'customer', sortable: false },
    { title: 'Data', key: 'issue_date', sortable: true },
    { title: 'Scadenza', key: 'valid_until', sortable: true },
    { title: 'Totale', key: 'total', sortable: true, align: 'end' },
    { title: 'Stato', key: 'status', sortable: false },
    ...(props.owners ? [{ title: 'Responsabile', key: 'owner', sortable: false }] : []),
]);

const tabs = computed(() => [
    { value: null, title: 'Tutti', count: Object.values(props.counts).reduce((sum, item) => sum + item.count, 0) },
    ...props.statuses.map((status) => ({ ...status, count: props.counts[status.value]?.count ?? 0 })),
]);

const openAmount = computed(() => props.counts.sent?.amount ?? 0);
const acceptedAmount = computed(() => props.counts.accepted?.amount ?? 0);

function load(params = {}) {
    router.get(
        route('sales.quotes.index'),
        {
            search: query.search,
            owner: query.owner ?? undefined,
            status: props.filters.status ?? undefined,
            organization: props.filters.organization ?? undefined,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['quotes', 'filters', 'counts'],
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
    const sortKey = sort?.key ?? 'number';
    const sortDir = sort?.order ?? 'desc';

    if (
        page !== props.quotes.meta.current_page ||
        itemsPerPage !== props.filters.per_page ||
        sortKey !== props.filters.sort_by ||
        sortDir !== props.filters.sort_dir
    ) {
        load({ page, per_page: itemsPerPage, sort_by: sortKey, sort_dir: sortDir });
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
    () => query.owner,
    () => load({ page: 1 }),
);

function open(event, { item }) {
    router.visit(route('sales.quotes.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader title="Preventivi" :subtitle="owners ? 'Preventivi di tutto il team' : 'I tuoi preventivi'">
            <template v-if="can('quotes.create')" #actions>
                <v-btn color="primary" prepend-icon="mdi-file-document-plus-outline" v-bind="link(route('sales.quotes.create'))">
                    Nuovo preventivo
                </v-btn>
            </template>
        </PageHeader>

        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="4">
                <v-card>
                    <v-card-item>
                        <template #prepend>
                            <v-avatar color="info" variant="tonal"><v-icon icon="mdi-send-outline" /></v-avatar>
                        </template>
                        <v-card-subtitle>In attesa di risposta</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(openAmount) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card>
                    <v-card-item>
                        <template #prepend>
                            <v-avatar color="success" variant="tonal"><v-icon icon="mdi-check-decagram-outline" /></v-avatar>
                        </template>
                        <v-card-subtitle>Accettati</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(acceptedAmount) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-tabs
                :model-value="filters.status"
                color="primary"
                show-arrows
                @update:model-value="(status) => load({ status: status ?? undefined, page: 1 })"
            >
                <v-tab v-for="tab in tabs" :key="tab.value ?? 'all'" :value="tab.value">
                    {{ tab.title }}
                    <v-badge v-if="tab.count" :content="tab.count" :color="tab.color ?? 'secondary'" inline />
                </v-tab>
            </v-tabs>
            <v-divider />

            <v-card-text>
                <v-row dense>
                    <v-col cols="12" :md="owners ? 8 : 12">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per numero, oggetto o cliente"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col v-if="owners" cols="12" md="4">
                        <v-select v-model="query.owner" :items="owners" label="Responsabile" clearable hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="quotes.data"
                :items-length="quotes.meta.total"
                :page="quotes.meta.current_page"
                :items-per-page="filters.per_page"
                :items-per-page-options="[10, 25, 50, 100]"
                :sort-by="[{ key: filters.sort_by, order: filters.sort_dir }]"
                :loading="loading"
                must-sort
                hover
                class="cursor-pointer"
                @update:options="onOptions"
                @click:row="open"
            >
                <template #[`item.number`]="{ item }">
                    <span class="font-weight-medium text-no-wrap">{{ item.number }}</span>
                </template>
                <template #[`item.customer`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.customer.name }}</div>
                        <div v-if="item.title" class="text-caption text-medium-emphasis">{{ item.title }}</div>
                    </div>
                </template>
                <template #[`item.issue_date`]="{ item }">{{ formatDate(item.issue_date) }}</template>
                <template #[`item.valid_until`]="{ item }">
                    <span :class="{ 'text-warning font-weight-bold': item.is_expiring }">{{ formatDate(item.valid_until) }}</span>
                </template>
                <template #[`item.total`]="{ item }">
                    <span class="text-no-wrap font-weight-medium">{{ formatMoney(item.total) }}</span>
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip :color="item.status_color" :prepend-icon="item.status_icon" variant="tonal">{{ item.status_label }}</v-chip>
                </template>
                <template #[`item.owner`]="{ item }">{{ item.owner?.name ?? '—' }}</template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-file-document-outline"
                        title="Nessun preventivo"
                        :text="filters.search ? 'Prova a modificare i filtri.' : 'Crea il primo preventivo.'"
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
