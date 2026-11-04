<script setup>
import { reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    invoices: { type: Object, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
    sdi: { type: Object, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const loading = ref(false);
const query = reactive({ search: props.filters.search });

const headers = [
    { title: 'Data', key: 'document_date', sortable: false },
    { title: 'Fornitore', key: 'supplier_name', sortable: false },
    { title: 'Numero', key: 'number', sortable: false },
    { title: 'Totale', key: 'total', sortable: false, align: 'end' },
    { title: '', key: 'actions', sortable: false, align: 'end' },
];

const tabs = [
    { value: 'new', title: 'Da registrare' },
    { value: 'recorded', title: 'Registrate' },
    { value: 'ignored', title: 'Ignorate' },
    { value: 'all', title: 'Tutte' },
];

function load(params = {}) {
    router.get(
        route('purchases.received.index'),
        { search: query.search || undefined, status: props.filters.status, per_page: props.filters.per_page, ...params },
        { preserveState: true, preserveScroll: true, replace: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) },
    );
}

let timeout;
watch(
    () => query.search,
    () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => load({ page: 1 }), 350);
    },
);

function onOptions({ page, itemsPerPage }) {
    if (page !== props.invoices.current_page || itemsPerPage !== props.filters.per_page) {
        load({ page, per_page: itemsPerPage });
    }
}

// --- Import ---------------------------------------------------------------------------------
const input = ref(null);
const importForm = useForm({ files: [] });

function pick(event) {
    const files = [...(event.target.files ?? [])];

    if (!files.length) {
        return;
    }

    importForm.files = files;
    importForm.post(route('purchases.received.import'), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            importForm.reset();
            event.target.value = '';
        },
    });
}

function record(item) {
    router.post(route('purchases.received.record', item.id));
}

function setStatus(item, status) {
    router.patch(route('purchases.received.status', item.id), { status }, { preserveScroll: true });
}
</script>

<template>
    <div>
        <PageHeader title="Fatture ricevute" subtitle="Fatture elettroniche dei fornitori arrivate dallo SDI o importate da Fatture e Corrispettivi">
            <template v-if="can('expenses.manage')" #actions>
                <input ref="input" type="file" multiple accept=".xml,.p7m,.zip" class="d-none" @change="pick" />
                <v-btn color="primary" prepend-icon="mdi-file-import-outline" :loading="importForm.processing" @click="input.click()">
                    Importa file
                </v-btn>
            </template>
        </PageHeader>

        <v-alert v-if="!sdi.enabled" type="info" variant="tonal" class="mb-4" icon="mdi-information-outline">
            Le fatture arrivano qui da sole collegando Openapi e registrando il codice destinatario
            <strong>{{ sdi.recipient_code }}</strong>
            su Fatture e Corrispettivi. Intanto puoi importare gli XML, i .p7m o lo ZIP scaricati dal portale: i doppioni vengono saltati.
            <template v-if="can('settings.manage')" #append>
                <v-btn variant="text" size="small" v-bind="link(route('settings.einvoicing.index'))">Configura</v-btn>
            </template>
        </v-alert>

        <v-card>
            <v-tabs :model-value="filters.status" color="primary" @update:model-value="(status) => load({ status, page: 1 })">
                <v-tab v-for="tab in tabs" :key="tab.value" :value="tab.value">
                    {{ tab.title }}
                    <v-badge v-if="tab.value === 'new' && counts.new" :content="counts.new" color="primary" inline />
                </v-tab>
            </v-tabs>
            <v-divider />
            <v-card-text>
                <v-text-field
                    v-model="query.search"
                    label="Cerca per fornitore, P.IVA o numero"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    hide-details
                />
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="invoices.data"
                :items-length="invoices.total"
                :page="invoices.current_page"
                :items-per-page="filters.per_page"
                :items-per-page-options="[25, 50, 100]"
                :loading="loading"
                hover
                @update:options="onOptions"
            >
                <template #[`item.document_date`]="{ item }">
                    <div class="text-no-wrap">{{ formatDate(item.document_date) }}</div>
                    <div class="text-caption text-medium-emphasis">{{ item.source === 'sdi' ? 'Dallo SDI' : 'Importata' }}</div>
                </template>
                <template #[`item.supplier_name`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.supplier_name ?? '—' }}</div>
                        <div class="text-caption text-medium-emphasis">{{ item.supplier_vat }}</div>
                    </div>
                </template>
                <template #[`item.number`]="{ item }">
                    <div class="text-no-wrap">{{ item.number }}</div>
                    <div v-if="item.is_credit_note" class="text-caption text-success">Nota di credito</div>
                </template>
                <template #[`item.total`]="{ item }">
                    <span class="text-no-wrap font-weight-medium">{{ formatMoney(item.total) }}</span>
                </template>
                <template #[`item.actions`]="{ item }">
                    <div class="d-flex justify-end ga-1">
                        <v-btn
                            v-if="item.expense_id"
                            size="small"
                            variant="tonal"
                            prepend-icon="mdi-receipt-text-check-outline"
                            v-bind="link(route('purchases.expenses.show', item.expense_id))"
                        >
                            Spesa
                        </v-btn>
                        <template v-else-if="can('expenses.manage')">
                            <v-btn
                                v-if="item.status !== 'ignored'"
                                size="small"
                                color="primary"
                                variant="tonal"
                                prepend-icon="mdi-plus"
                                @click="record(item)"
                            >
                                Registra spesa
                            </v-btn>
                            <v-btn
                                :icon="item.status === 'ignored' ? 'mdi-restore' : 'mdi-eye-off-outline'"
                                size="small"
                                variant="text"
                                :title="item.status === 'ignored' ? 'Ripristina' : 'Ignora (non è una spesa da registrare)'"
                                @click="setStatus(item, item.status === 'ignored' ? 'new' : 'ignored')"
                            />
                        </template>
                        <v-btn
                            icon="mdi-file-code-outline"
                            size="small"
                            variant="text"
                            title="Scarica XML"
                            :href="route('purchases.received.file', item.id)"
                        />
                    </div>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-inbox-outline"
                        title="Nessuna fattura"
                        text="Qui arrivano le fatture elettroniche dei fornitori."
                        size="48"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
