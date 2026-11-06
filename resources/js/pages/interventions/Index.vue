<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import InterventionInvoiceDialog from '@/components/interventions/InterventionInvoiceDialog.vue';
import InterventionStartDialog from '@/components/interventions/InterventionStartDialog.vue';
import InvoicedOutsideDialog from '@/components/interventions/InvoicedOutsideDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { useToast } from '@/composables/useToast';
import { formatDate } from '@/utils/format';
import { formatHours, formatMinutes, ROUNDING_LABELS } from '@/utils/duration';

const props = defineProps({
    interventions: { type: Object, required: true },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    customers: { type: Array, required: true },
    categories: { type: Array, required: true },
    technicians: { type: Array, default: null },
    modes: { type: Array, required: true },
    statuses: { type: Array, required: true },
    rounding: { type: Number, required: true },
    /** «Segna come già fatturati» (invoiced outside Prugna). */
    canMarkInvoiced: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const toast = useToast();

const loading = ref(false);
const startDialog = ref(false);
const invoicedOutsideDialog = ref(false);
const query = reactive({
    search: props.filters.search,
    customer: props.filters.customer,
    category: props.filters.category,
    mode: props.filters.mode,
    technician: props.filters.technician,
    from: props.filters.from,
    to: props.filters.to,
});

const headers = [
    { title: 'Data', key: 'started_at', sortable: true },
    { title: 'Cliente', key: 'organization', sortable: false },
    { title: 'Categoria', key: 'category', sortable: false },
    ...(props.technicians ? [{ title: 'Tecnico', key: 'technician', sortable: false }] : []),
    { title: 'Durata', key: 'duration_minutes', sortable: true, align: 'end' },
    { title: 'Stato', key: 'status', sortable: false },
];

const tabs = [{ value: null, title: 'Tutti' }, ...props.statuses];

function load(params = {}) {
    router.get(
        route('interventions.index'),
        {
            search: query.search || undefined,
            customer: query.customer ?? undefined,
            category: query.category ?? undefined,
            mode: query.mode ?? undefined,
            technician: query.technician ?? undefined,
            from: query.from || undefined,
            to: query.to || undefined,
            scope: props.filters.scope,
            status: props.filters.status ?? undefined,
            order: props.filters.order ?? undefined,
            sort_by: props.filters.sort_by ?? undefined,
            sort_dir: props.filters.sort_dir ?? undefined,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['interventions', 'filters', 'summary'],
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
        page !== props.interventions.meta.current_page ||
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
    () => [query.customer, query.category, query.mode, query.technician, query.from, query.to],
    () => load({ page: 1 }),
);

/**
 * Quick periods for the date filter.
 */
function setPeriod(period) {
    const today = new Date();
    const iso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    if (period === 'month') {
        query.from = iso(new Date(today.getFullYear(), today.getMonth(), 1));
        query.to = iso(new Date(today.getFullYear(), today.getMonth() + 1, 0));
    } else if (period === 'last') {
        query.from = iso(new Date(today.getFullYear(), today.getMonth() - 1, 1));
        query.to = iso(new Date(today.getFullYear(), today.getMonth(), 0));
    } else {
        query.from = null;
        query.to = null;
    }
}

function open(event, { item }) {
    router.visit(route('interventions.show', item.id));
}

// --- Invoicing ------------------------------------------------------------------------------
const selected = ref([]);
const invoiceDialog = ref(false);
const invoiceCustomer = ref(null);
const invoicePreselect = ref(null);
const canInvoice = computed(() => can('invoices.manage'));

function invoiceSelected() {
    const items = props.interventions.data.filter((item) => selected.value.includes(item.id));
    const customers = [...new Set(items.map((item) => item.organization_id))];

    if (customers.length !== 1) {
        toast.error('Scegli interventi di un solo cliente: la fattura è una per cliente.');

        return;
    }

    if (items.some((item) => item.status !== 'to_invoice')) {
        toast.error('Alcuni interventi scelti non sono da fatturare (in corso, già fatturati o non fatturabili).');

        return;
    }

    invoiceCustomer.value = customers[0];
    invoicePreselect.value = items.map((item) => item.id);
    invoiceDialog.value = true;
}

function invoiceCustomerAll() {
    invoiceCustomer.value = query.customer;
    invoicePreselect.value = null;
    invoiceDialog.value = true;
}

const selectable = (item) => item.status === 'to_invoice';
</script>

<template>
    <div>
        <PageHeader
            title="Interventi"
            :subtitle="`Assistenza da remoto e on site · tempo arrotondato ${ROUNDING_LABELS[rounding] ?? `a ${rounding} minuti`}`"
        >
            <template #actions>
                <v-btn v-if="canInvoice && query.customer" variant="tonal" prepend-icon="mdi-receipt-text-plus-outline" @click="invoiceCustomerAll">
                    Fattura interventi
                </v-btn>
                <v-btn v-if="canMarkInvoiced" variant="text" prepend-icon="mdi-receipt-text-check-outline" @click="invoicedOutsideDialog = true">
                    Già fatturati…
                </v-btn>
                <template v-if="can('interventions.manage')">
                    <v-btn variant="tonal" prepend-icon="mdi-timer-play-outline" @click="startDialog = true">Avvia</v-btn>
                    <v-btn color="primary" prepend-icon="mdi-plus" v-bind="link(route('interventions.create'))">Registra intervento</v-btn>
                </template>
            </template>
        </PageHeader>

        <v-row class="mb-2">
            <v-col cols="12" sm="4">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Ore lavorate ({{ summary.count }} interventi)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMinutes(summary.minutes) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Ore fatturabili (arrotondate)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatHours(summary.billable_minutes) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card :color="summary.to_invoice_count ? 'warning' : undefined" variant="tonal" @click="load({ status: 'to_invoice', page: 1 })">
                    <v-card-item>
                        <v-card-subtitle>
                            Da fatturare ({{ summary.to_invoice_count }} interventi, {{ summary.to_invoice_customers }} clienti)
                        </v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatHours(summary.to_invoice_minutes) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <div class="d-flex align-center flex-wrap">
                <v-tabs
                    :model-value="filters.status"
                    color="primary"
                    show-arrows
                    class="flex-grow-1"
                    @update:model-value="(status) => load({ status: status ?? undefined, page: 1 })"
                >
                    <v-tab v-for="tab in tabs" :key="tab.value ?? 'all'" :value="tab.value">{{ tab.title }}</v-tab>
                </v-tabs>
                <v-btn-toggle
                    v-if="technicians"
                    :model-value="filters.scope"
                    mandatory
                    density="compact"
                    variant="outlined"
                    color="primary"
                    class="mx-4 my-2"
                    @update:model-value="(scope) => load({ scope, page: 1 })"
                >
                    <v-btn value="mine">Miei</v-btn>
                    <v-btn value="all">Tutti</v-btn>
                </v-btn-toggle>
            </div>
            <v-divider />

            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per cliente, numero o descrizione"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="4">
                        <v-autocomplete v-model="query.customer" :items="customers" label="Cliente" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select v-model="query.category" :items="categories" label="Categoria" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select v-model="query.mode" :items="modes" label="Modalità" clearable hide-details />
                    </v-col>
                    <v-col v-if="technicians && filters.scope === 'all'" cols="12" sm="6" md="4">
                        <v-select v-model="query.technician" :items="technicians" label="Tecnico" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-text-field v-model="query.from" type="date" label="Dal" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-text-field v-model="query.to" type="date" label="Al" clearable hide-details />
                    </v-col>
                    <v-col cols="12" md="4" class="d-flex align-center ga-1">
                        <v-btn size="small" variant="text" @click="setPeriod('month')">Questo mese</v-btn>
                        <v-btn size="small" variant="text" @click="setPeriod('last')">Mese scorso</v-btn>
                        <v-btn v-if="query.from || query.to" size="small" variant="text" @click="setPeriod(null)">Sempre</v-btn>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-toolbar v-if="selected.length" density="compact" color="primary" class="px-4">
                <span class="text-body-2">{{ selected.length }} selezionati</span>
                <v-spacer />
                <v-btn variant="flat" color="white" prepend-icon="mdi-receipt-text-plus-outline" @click="invoiceSelected">Fattura interventi</v-btn>
                <v-btn variant="text" icon="mdi-close" title="Annulla selezione" @click="selected = []" />
            </v-toolbar>

            <v-data-table-server
                v-model="selected"
                :show-select="canInvoice"
                :item-selectable="selectable"
                item-value="id"
                :headers="headers"
                :items="interventions.data"
                :items-length="interventions.meta.total"
                :page="interventions.meta.current_page"
                :items-per-page="filters.per_page"
                :items-per-page-options="[10, 25, 50, 100]"
                :loading="loading"
                hover
                class="cursor-pointer"
                @update:options="onOptions"
                @click:row="open"
            >
                <template #[`item.started_at`]="{ item }">
                    <div class="py-2 text-no-wrap">
                        <div class="font-weight-medium">{{ formatDate(item.started_at) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ new Date(item.started_at).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) }} · {{ item.number }}
                        </div>
                    </div>
                </template>
                <template #[`item.organization`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.organization?.name }}</div>
                        <div v-if="item.description" class="text-caption text-medium-emphasis text-truncate" style="max-width: 360px">
                            {{ item.description }}
                        </div>
                        <div v-if="item.order" class="text-caption">
                            <v-icon icon="mdi-clipboard-text-outline" size="x-small" />
                            {{ item.order.number }}
                        </div>
                    </div>
                </template>
                <template #[`item.category`]="{ item }">
                    <div class="text-no-wrap">{{ item.category }}</div>
                    <div class="text-caption text-medium-emphasis text-no-wrap">
                        <v-icon :icon="item.mode_icon" size="x-small" />
                        {{ item.mode_label }}
                    </div>
                </template>
                <template #[`item.duration_minutes`]="{ item }">
                    <template v-if="item.running">
                        <v-icon icon="mdi-timer-outline" size="small" color="info" />
                    </template>
                    <template v-else>
                        <div class="text-no-wrap font-weight-medium">{{ formatMinutes(item.duration_minutes) }}</div>
                        <div
                            v-if="item.billable_minutes !== item.duration_minutes && item.billable"
                            class="text-caption text-medium-emphasis text-no-wrap"
                        >
                            fatt. {{ formatHours(item.billable_minutes) }}
                        </div>
                    </template>
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip size="small" :color="item.status_color" variant="tonal">{{ item.status_label }}</v-chip>
                    <div v-for="invoice in item.invoices ?? []" :key="invoice.id" class="text-caption text-medium-emphasis">{{ invoice.label }}</div>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-timer-outline"
                        title="Nessun intervento"
                        text="Avvia il timer quando inizi un lavoro, oppure registra un intervento già fatto."
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>

        <InterventionStartDialog v-model="startDialog" :organization-id="query.customer" />
        <InvoicedOutsideDialog v-if="canMarkInvoiced" v-model="invoicedOutsideDialog" />
        <InterventionInvoiceDialog
            v-model="invoiceDialog"
            :organization-id="invoiceCustomer"
            :order-id="filters.order"
            :preselect="invoicePreselect"
        />
    </div>
</template>
