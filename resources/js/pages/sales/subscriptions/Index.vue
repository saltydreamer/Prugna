<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import SubscriptionInvoiceDialog from '@/components/sales/SubscriptionInvoiceDialog.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    subscriptions: { type: Object, required: true },
    filters: { type: Object, required: true },
    customers: { type: Array, required: true },
    dueCount: { type: Number, required: true },
    canInvoice: { type: Boolean, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const loading = ref(false);
const generating = ref(false);
const query = reactive({ search: props.filters.search, customer: props.filters.customer });

const headers = [
    { title: 'Contratto', key: 'title', sortable: false },
    { title: 'Cliente', key: 'organization', sortable: false },
    { title: 'Canone', key: 'amount', sortable: false, align: 'end' },
    { title: 'Prossimo canone', key: 'next_period', sortable: false },
    { title: 'Stato', key: 'status', sortable: false },
];

function load(params = {}) {
    router.get(
        route('sales.subscriptions.index'),
        { search: query.search || undefined, customer: query.customer ?? undefined, status: props.filters.status, ...params },
        { preserveState: true, replace: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) },
    );
}

let timeout;
watch(
    () => query.search,
    () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => load(), 350);
    },
);
watch(
    () => query.customer,
    () => load(),
);
</script>

<template>
    <div>
        <PageHeader title="Contratti servizi" subtitle="Servizi ricorrenti addebitati ai clienti a canone">
            <template #actions>
                <v-btn v-if="canInvoice" color="primary" variant="tonal" prepend-icon="mdi-receipt-text-clock-outline" @click="generating = true">
                    Genera fatture da servizi
                    <v-badge v-if="dueCount" :content="dueCount" color="primary" inline class="ml-1" />
                </v-btn>
                <v-btn v-if="can('subscriptions.manage')" color="primary" prepend-icon="mdi-plus" v-bind="link(route('sales.subscriptions.create'))">
                    Nuovo contratto
                </v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-tabs :model-value="filters.status" color="primary" @update:model-value="(status) => load({ status })">
                <v-tab value="active">Attivi</v-tab>
                <v-tab value="suspended">Sospesi</v-tab>
                <v-tab value="all">Tutti</v-tab>
            </v-tabs>
            <v-divider />
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="6">
                        <v-text-field v-model="query.search" label="Cerca" prepend-inner-icon="mdi-magnify" clearable hide-details />
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-autocomplete v-model="query.customer" :items="customers" label="Cliente" clearable hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table
                :headers="headers"
                :items="subscriptions.data"
                :loading="loading"
                :items-per-page="-1"
                hide-default-footer
                hover
                class="cursor-pointer"
                @click:row="(event, { item }) => router.visit(route('sales.subscriptions.show', item.id))"
            >
                <template #[`item.title`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.title }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ item.number }} · {{ item.billing_period_label.toLowerCase() }}, {{ item.billing_mode_label.toLowerCase() }}
                        </div>
                    </div>
                </template>
                <template #[`item.organization`]="{ item }">{{ item.organization?.name }}</template>
                <template #[`item.amount`]="{ item }">
                    <div class="text-no-wrap">{{ formatMoney(item.amount) }}</div>
                    <div class="text-caption text-medium-emphasis text-no-wrap">{{ item.billing_period_per }}</div>
                </template>
                <template #[`item.next_period`]="{ item }">
                    <template v-if="item.next_period">
                        <div class="text-body-2">{{ item.next_period.label }}</div>
                        <div class="text-caption" :class="item.next_period.is_due ? 'text-warning font-weight-medium' : 'text-medium-emphasis'">
                            {{ item.next_period.is_due ? 'da fatturare dal' : 'si fattura il' }} {{ formatDate(item.next_period.due_on) }}
                        </div>
                    </template>
                    <span v-else class="text-medium-emphasis">—</span>
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip size="small" :color="item.status_color" variant="tonal">{{ item.status_label }}</v-chip>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-autorenew"
                        title="Nessun contratto"
                        text="Inserisci i servizi ricorrenti dei clienti (hosting, manutenzione, assistenza...): i canoni da fatturare li trovi in «Genera fatture da servizi»."
                        size="48"
                    />
                </template>
            </v-data-table>
        </v-card>

        <SubscriptionInvoiceDialog v-model="generating" />
    </div>
</template>
