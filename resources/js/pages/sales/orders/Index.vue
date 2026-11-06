<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    counts: { type: Object, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const loading = ref(false);
const query = reactive({ search: props.filters.search });

const headers = [
    { title: 'Numero', key: 'number', sortable: true },
    { title: 'Cliente', key: 'customer', sortable: false },
    { title: 'Data', key: 'order_date', sortable: true },
    { title: 'Consegna', key: 'delivery_date', sortable: true },
    { title: 'Totale', key: 'total', sortable: true, align: 'end' },
    { title: 'Fatturato', key: 'invoiced_percent', sortable: false, width: 140 },
    { title: 'Stato', key: 'status', sortable: false },
];

const tabs = computed(() => [
    { value: 'active', title: 'Aperti', count: props.counts.active ?? 0, color: 'primary' },
    { value: 'to_invoice', title: 'Da fatturare', count: props.counts.to_invoice ?? 0, color: 'warning' },
    ...props.statuses.map((status) => ({ ...status, count: props.counts[status.value] ?? 0 })),
]);

function load(params = {}) {
    router.get(
        route('sales.orders.index'),
        {
            search: query.search,
            status: props.filters.status,
            organization: props.filters.organization ?? undefined,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['orders', 'filters', 'counts'],
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
    const sortKey = sort?.key ?? 'order_date';
    const sortDir = sort?.order ?? 'desc';

    if (
        page !== props.orders.meta.current_page ||
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

function open(event, { item }) {
    router.visit(route('sales.orders.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader title="Ordini clienti" subtitle="Dalla conferma alla fatturazione">
            <template v-if="can('orders.manage')" #actions>
                <v-btn color="primary" prepend-icon="mdi-clipboard-plus-outline" v-bind="link(route('sales.orders.create'))">Nuovo ordine</v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-tabs :model-value="filters.status" color="primary" show-arrows @update:model-value="(status) => load({ status, page: 1 })">
                <v-tab v-for="tab in tabs" :key="tab.value" :value="tab.value">
                    {{ tab.title }}
                    <v-badge v-if="tab.count" :content="tab.count" :color="tab.color ?? 'secondary'" inline />
                </v-tab>
            </v-tabs>
            <v-divider />

            <v-card-text>
                <v-text-field
                    v-model="query.search"
                    label="Cerca per numero, oggetto, riferimento o cliente"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    hide-details
                    @click:clear="query.search = ''"
                />
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="orders.data"
                :items-length="orders.meta.total"
                :page="orders.meta.current_page"
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
                <template #[`item.order_date`]="{ item }">{{ formatDate(item.order_date) }}</template>
                <template #[`item.delivery_date`]="{ item }">
                    <span :class="{ 'text-error font-weight-bold': item.is_late }">{{ formatDate(item.delivery_date) }}</span>
                </template>
                <template #[`item.total`]="{ item }">
                    <span class="text-no-wrap font-weight-medium">{{ formatMoney(item.total) }}</span>
                </template>
                <template #[`item.invoiced_percent`]="{ item }">
                    <v-progress-linear
                        :model-value="item.invoiced_percent"
                        :color="item.invoiced_percent >= 100 ? 'success' : 'primary'"
                        height="16"
                        rounded
                    >
                        <span class="text-caption">{{ Math.round(item.invoiced_percent) }}%</span>
                    </v-progress-linear>
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip :color="item.status_color" :prepend-icon="item.status_icon" variant="tonal">{{ item.status_label }}</v-chip>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-clipboard-text-outline"
                        title="Nessun ordine"
                        text="Gli ordini nascono dai preventivi accettati, oppure creane uno a mano."
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
