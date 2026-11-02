<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    purchaseOrders: { type: Object, required: true },
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
    { title: 'Fornitore', key: 'supplier', sortable: false },
    { title: 'Per', key: 'customer', sortable: false },
    { title: 'Data', key: 'order_date', sortable: true },
    { title: 'Consegna', key: 'expected_date', sortable: true },
    { title: 'Totale', key: 'total', sortable: true, align: 'end' },
    { title: 'Stato', key: 'status', sortable: false },
];

const tabs = computed(() => [
    { value: 'open', title: 'Aperti', count: props.counts.open ?? 0, color: 'primary' },
    ...props.statuses.map((status) => ({ ...status, count: props.counts[status.value] ?? 0 })),
    { value: 'all', title: 'Tutti', count: props.counts.all ?? 0, color: 'secondary' },
]);

function load(params = {}) {
    router.get(
        route('purchases.orders.index'),
        {
            search: query.search,
            status: props.filters.status,
            supplier: props.filters.supplier ?? undefined,
            customer: props.filters.customer ?? undefined,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['purchaseOrders', 'filters', 'counts'],
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
        page !== props.purchaseOrders.meta.current_page ||
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
    router.visit(route('purchases.orders.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader title="Ordini fornitore" subtitle="Acquisti e costi da ribaltare ai clienti">
            <template v-if="can('purchases.manage')" #actions>
                <v-btn color="primary" prepend-icon="mdi-cart-plus" v-bind="link(route('purchases.orders.create'))">Nuovo ordine fornitore</v-btn>
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
                    label="Cerca per numero, oggetto, riferimento o fornitore"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    hide-details
                    @click:clear="query.search = ''"
                />
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="purchaseOrders.data"
                :items-length="purchaseOrders.meta.total"
                :page="purchaseOrders.meta.current_page"
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
                <template #[`item.supplier`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.supplier.name }}</div>
                        <div v-if="item.title" class="text-caption text-medium-emphasis">{{ item.title }}</div>
                    </div>
                </template>
                <template #[`item.customer`]="{ item }">
                    <div v-if="item.customer" class="text-body-2">{{ item.customer.name }}</div>
                    <div v-if="item.order" class="text-caption text-medium-emphasis">{{ item.order.number }}</div>
                    <span v-if="!item.customer && !item.order" class="text-medium-emphasis">Interno</span>
                </template>
                <template #[`item.order_date`]="{ item }">{{ formatDate(item.order_date) }}</template>
                <template #[`item.expected_date`]="{ item }">
                    <span :class="{ 'text-error font-weight-bold': item.is_late }">{{ formatDate(item.expected_date) }}</span>
                </template>
                <template #[`item.total`]="{ item }">
                    <span class="text-no-wrap font-weight-medium">{{ formatMoney(item.total) }}</span>
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip :color="item.status_color" :prepend-icon="item.status_icon" variant="tonal">{{ item.status_label }}</v-chip>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-cart-outline"
                        title="Nessun ordine fornitore"
                        text="Crea un ordine al fornitore, anche partendo da un ordine cliente."
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
