<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatPercent } from '@/utils/format';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    categories: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const loading = ref(false);
const query = reactive({
    search: props.filters.search,
    type: props.filters.type,
    category: props.filters.category,
    status: props.filters.status,
    recurring: props.filters.recurring,
});

const statusOptions = [
    { value: 'active', title: 'Attivi' },
    { value: 'inactive', title: 'Disattivati' },
    { value: 'all', title: 'Tutti' },
];

const headers = [
    { title: 'Codice', key: 'code', sortable: true },
    { title: 'Articolo', key: 'name', sortable: true },
    { title: 'Prezzo', key: 'price', sortable: true, align: 'end' },
    { title: 'Margine', key: 'margin', sortable: false, align: 'end' },
    { title: 'IVA', key: 'vat', sortable: false },
    { title: '', key: 'actions', sortable: false, align: 'end' },
];

function load(params = {}) {
    router.get(
        route('catalog.products.index'),
        {
            ...query,
            recurring: query.recurring ? 1 : undefined,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['products', 'filters'],
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
    const sortKey = sort?.key ?? 'name';
    const sortDir = sort?.order ?? 'asc';

    if (
        page !== props.products.meta.current_page ||
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
    () => [query.type, query.category, query.status, query.recurring],
    () => load({ page: 1 }),
);

function open(event, { item }) {
    router.visit(route('catalog.products.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader title="Prodotti e servizi" :subtitle="`${products.meta.total} articoli`">
            <template v-if="can('products.create')" #actions>
                <v-btn
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-account-wrench-outline"
                    v-bind="link(route('catalog.products.create', { type: 'service' }))"
                >
                    Nuovo servizio
                </v-btn>
                <v-btn color="primary" prepend-icon="mdi-package-variant-plus" v-bind="link(route('catalog.products.create'))">Nuovo prodotto</v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-card-text>
                <v-row dense align="center">
                    <v-col cols="12" md="5">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per codice, nome o descrizione"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select v-model="query.type" :items="types" label="Tipo" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-select
                            v-model="query.category"
                            :items="categories"
                            item-title="name"
                            item-value="id"
                            label="Categoria"
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select v-model="query.status" :items="statusOptions" label="Stato" hide-details />
                    </v-col>
                    <v-col cols="6" md="12">
                        <v-checkbox v-model="query.recurring" label="Solo servizi a canone" hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="products.data"
                :items-length="products.meta.total"
                :page="products.meta.current_page"
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
                <template #[`item.code`]="{ item }">
                    <code :class="{ 'text-disabled': !item.is_active }">{{ item.code }}</code>
                </template>

                <template #[`item.name`]="{ item }">
                    <div class="py-2" :class="{ 'text-disabled': !item.is_active }">
                        <div class="d-flex align-center ga-2">
                            <v-icon
                                :icon="item.type === 'service' ? 'mdi-account-wrench-outline' : 'mdi-package-variant-closed'"
                                size="small"
                                :title="item.type_label"
                            />
                            <span class="font-weight-medium">{{ item.name }}</span>
                            <v-chip v-if="item.billing_period" color="info" variant="tonal" prepend-icon="mdi-autorenew">
                                {{ item.billing_period_label }}
                            </v-chip>
                            <v-chip v-if="!item.is_active" variant="outlined">Disattivato</v-chip>
                        </div>
                        <div class="d-flex align-center ga-2 mt-1">
                            <v-chip v-if="item.category" :color="item.category.color" variant="tonal" size="x-small">{{ item.category.name }}</v-chip>
                            <span v-if="item.customer_prices_count" class="text-caption text-medium-emphasis">
                                <v-icon icon="mdi-tag-outline" size="x-small" />
                                {{ item.customer_prices_count === 1 ? '1 prezzo concordato' : `${item.customer_prices_count} prezzi concordati` }}
                            </span>
                        </div>
                    </div>
                </template>

                <template #[`item.price`]="{ item }">
                    <div class="text-no-wrap font-weight-medium">{{ formatMoney(item.price) }}</div>
                    <div class="text-caption text-medium-emphasis text-no-wrap">{{ item.billing_period_per ?? `/ ${item.unit}` }}</div>
                </template>

                <template #[`item.margin`]="{ item }">
                    <span v-if="item.margin" :class="item.margin.amount < 0 ? 'text-error' : ''">{{ formatPercent(item.margin.percent) }}</span>
                    <span v-else class="text-medium-emphasis">—</span>
                </template>

                <template #[`item.vat`]="{ item }">
                    <span class="text-no-wrap">{{ item.vat_rate?.nature ?? `${item.vat_rate?.rate}%` }}</span>
                </template>

                <template #[`item.actions`]="{ item }">
                    <v-btn
                        v-if="item.can?.update"
                        icon="mdi-pencil-outline"
                        variant="text"
                        size="small"
                        title="Modifica"
                        v-bind="link(route('catalog.products.edit', item.id))"
                        @click.stop
                    />
                </template>

                <template #no-data>
                    <v-empty-state
                        icon="mdi-package-variant"
                        title="Nessun articolo"
                        :text="filters.search ? 'Prova a modificare i filtri.' : 'Crea il primo prodotto o servizio.'"
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
