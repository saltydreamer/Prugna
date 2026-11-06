<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    invoices: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    years: { type: Array, required: true },
    types: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const loading = ref(false);
const query = reactive({ search: props.filters.search, type: props.filters.type, year: props.filters.year, payment: props.filters.payment });

const yearItems = computed(() => [...props.years, { title: 'Tutti gli anni', value: 'all' }]);
const paymentFilters = [
    { value: 'unpaid', title: 'Da incassare' },
    { value: 'overdue', title: 'Scadute' },
    { value: 'paid', title: 'Pagate' },
];

const headers = [
    { title: 'Numero', key: 'number', sortable: true },
    { title: 'Cliente', key: 'customer', sortable: false },
    { title: 'Data', key: 'issue_date', sortable: true },
    { title: 'Scadenza', key: 'due_date', sortable: true },
    { title: 'Totale', key: 'total', sortable: true, align: 'end' },
    { title: 'Netto a pagare', key: 'amount_due', sortable: false, align: 'end' },
    { title: 'Pagamento', key: 'payment', sortable: false },
    { title: 'SDI', key: 'sdi_status', sortable: false },
];

const statusTabs = [
    { value: null, title: 'Tutte' },
    { value: 'draft', title: 'Bozze' },
    { value: 'issued', title: 'Emesse' },
];

function load(params = {}) {
    router.get(
        route('sales.invoices.index'),
        {
            search: query.search,
            type: query.type ?? undefined,
            year: query.year,
            payment: query.payment ?? undefined,
            status: props.filters.status ?? undefined,
            organization: props.filters.organization ?? undefined,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['invoices', 'filters', 'summary'],
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
    const sortKey = sort?.key ?? 'issue_date';
    const sortDir = sort?.order ?? 'desc';

    if (
        page !== props.invoices.meta.current_page ||
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
    () => [query.type, query.year, query.payment],
    () => load({ page: 1 }),
);

/**
 * What customers still have to pay, whatever the year.
 */
function showToCollect(payment = 'unpaid') {
    query.payment = payment;
    query.year = 'all';
}

function open(event, { item }) {
    router.visit(route('sales.invoices.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader title="Fatture" subtitle="Fatture, acconti e note di credito">
            <template v-if="can('invoices.manage')" #actions>
                <v-btn color="primary" prepend-icon="mdi-receipt-text-plus-outline" v-bind="link(route('sales.invoices.create'))">
                    Nuova fattura
                </v-btn>
            </template>
        </PageHeader>

        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="4">
                <v-card>
                    <v-card-item>
                        <template #prepend>
                            <v-avatar color="success" variant="tonal"><v-icon icon="mdi-cash-check" /></v-avatar>
                        </template>
                        <v-card-subtitle>
                            Fatturato {{ filters.year === 'all' ? 'di tutti gli anni' : filters.year }} (imponibile, al netto delle note di credito)
                        </v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(summary.invoiced) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card @click="load({ status: 'draft', page: 1 })">
                    <v-card-item>
                        <template #prepend>
                            <v-avatar color="warning" variant="tonal"><v-icon icon="mdi-file-document-edit-outline" /></v-avatar>
                        </template>
                        <v-card-subtitle>Bozze da emettere</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ summary.drafts }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card
                    :color="summary.overdue_count ? 'error' : undefined"
                    :variant="summary.overdue_count ? 'tonal' : undefined"
                    @click="showToCollect()"
                >
                    <v-card-item>
                        <template #prepend>
                            <v-avatar color="warning" variant="tonal"><v-icon icon="mdi-cash-clock" /></v-avatar>
                        </template>
                        <v-card-subtitle>Da incassare ({{ summary.to_collect_count }} fatture, tutti gli anni)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(summary.to_collect) }}</v-card-title>
                        <div v-if="summary.overdue_count" class="text-caption">
                            di cui scaduti
                            <span
                                role="button"
                                tabindex="0"
                                class="font-weight-medium text-decoration-underline"
                                @click.stop="showToCollect('overdue')"
                                @keydown.enter.stop="showToCollect('overdue')"
                            >
                                {{ formatMoney(summary.overdue) }} ({{ summary.overdue_count }})
                            </span>
                        </div>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-tabs :model-value="filters.status" color="primary" @update:model-value="(status) => load({ status: status ?? undefined, page: 1 })">
                <v-tab v-for="tab in statusTabs" :key="tab.value ?? 'all'" :value="tab.value">{{ tab.title }}</v-tab>
            </v-tabs>
            <v-divider />

            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per numero, oggetto o cliente"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col cols="12" sm="4" md="3">
                        <v-select v-model="query.payment" :items="paymentFilters" label="Pagamento" clearable hide-details />
                    </v-col>
                    <v-col cols="6" sm="4" md="2">
                        <v-select v-model="query.type" :items="types" label="Tipo" clearable hide-details />
                    </v-col>
                    <v-col cols="6" sm="4" md="3">
                        <v-select v-model="query.year" :items="yearItems" label="Anno" hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="invoices.data"
                :items-length="invoices.meta.total"
                :page="invoices.meta.current_page"
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
                    <div class="py-2">
                        <div class="font-weight-medium text-no-wrap">
                            {{ item.number ?? 'Bozza' }}
                            <v-chip v-if="item.status === 'draft'" size="x-small" variant="tonal" class="ml-1">Da emettere</v-chip>
                        </div>
                        <div v-if="item.type !== 'invoice'" class="text-caption" :class="item.type === 'credit_note' ? 'text-error' : 'text-info'">
                            {{ item.type_label }}
                        </div>
                    </div>
                </template>
                <template #[`item.customer`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.customer.name }}</div>
                        <div v-if="item.title" class="text-caption text-medium-emphasis">{{ item.title }}</div>
                    </div>
                </template>
                <template #[`item.issue_date`]="{ item }">{{ formatDate(item.issue_date) }}</template>
                <template #[`item.due_date`]="{ item }">
                    <span class="text-no-wrap" :class="{ 'text-error font-weight-medium': item.payment_status === 'overdue' }">
                        {{ formatDate(item.due_date) }}
                    </span>
                </template>
                <template #[`item.total`]="{ item }">
                    <span class="text-no-wrap" :class="{ 'text-error': item.type === 'credit_note' }">{{ formatMoney(item.total) }}</span>
                </template>
                <template #[`item.amount_due`]="{ item }">
                    <span class="text-no-wrap font-weight-medium">{{ formatMoney(item.amount_due) }}</span>
                </template>
                <template #[`item.payment`]="{ item }">
                    <template v-if="item.payment_status">
                        <v-chip size="x-small" :color="item.payment_status_color" variant="tonal" class="text-no-wrap">
                            {{ item.payment_status_label }}
                        </v-chip>
                        <div v-if="item.payment_status === 'paid' && item.paid_at" class="text-caption text-medium-emphasis text-no-wrap">
                            il {{ formatDate(item.paid_at) }}
                        </div>
                        <div v-else-if="item.paid_amount > 0 && item.outstanding > 0" class="text-caption text-medium-emphasis text-no-wrap">
                            restano {{ formatMoney(item.outstanding) }}
                        </div>
                    </template>
                    <span v-else-if="item.status === 'issued'" class="text-caption text-medium-emphasis">—</span>
                </template>
                <template #[`item.sdi_status`]="{ item }">
                    <v-chip v-if="item.sdi_status" size="x-small" :color="item.sdi_status_color" variant="tonal">{{ item.sdi_status_label }}</v-chip>
                    <span v-else-if="item.status === 'issued'" class="text-caption text-medium-emphasis">—</span>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-receipt-text-outline"
                        title="Nessuna fattura"
                        text="Fattura un preventivo accettato o un ordine con un clic, oppure crea una fattura libera."
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
