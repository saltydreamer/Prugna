<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import QuoteLinesEditor from '@/components/sales/QuoteLinesEditor.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import ContactDialog from '@/components/registry/ContactDialog.vue';
import QuickOrganizationDialog from '@/components/registry/QuickOrganizationDialog.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utils/format';
import { http } from '@/utils/http';
import { lineTotal, quoteTotals } from '@/utils/quoteTotals';

const props = defineProps({
    /** null when creating. */
    purchaseOrder: { type: Object, default: null },
    defaults: { type: Object, default: null },
    /** Suppliers first ({ value, title, subtitle, is_supplier }). */
    organizations: { type: Array, required: true },
    customers: { type: Array, required: true },
    orders: { type: Array, default: () => [] },
    vatRates: { type: Array, required: true },
    paymentTerms: { type: Array, required: true },
    units: { type: Array, required: true },
    organizationTypes: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const isNew = computed(() => !props.purchaseOrder);
const source = props.purchaseOrder ?? props.defaults;
/** Supplier, customer and lines are frozen once a cost is in a customer invoice. */
const linesEditable = computed(() => isNew.value || Boolean(props.purchaseOrder.can?.edit_lines));

const form = useForm({
    supplier_id: source.supplier_id ?? null,
    contact_id: source.contact_id ?? null,
    owner_id: source.owner_id ?? null,
    customer_id: source.customer_id ?? null,
    order_id: source.order_id ?? null,
    title: source.title ?? '',
    order_date: source.order_date,
    expected_date: source.expected_date ?? null,
    supplier_reference: source.supplier_reference ?? '',
    payment_term_id: source.payment_term_id ?? null,
    notes: source.notes ?? '',
    internal_notes: source.internal_notes ?? '',
    lines: (source.lines ?? []).map((line, index) => ({ rebill: false, markup_percent: 0, ...line, key: `line-${line.id ?? index}` })),
});

const supplierItems = computed(() =>
    props.organizations.map((organization) => ({
        ...organization,
        subtitle: [organization.is_supplier ? 'Fornitore' : null, organization.subtitle].filter(Boolean).join(' · ') || undefined,
    })),
);

const defaultVatRateId = computed(() => props.vatRates.find((rate) => rate.is_default)?.id ?? props.vatRates[0]?.id ?? null);
const ratesById = computed(() => Object.fromEntries(props.vatRates.map((rate) => [rate.id, rate])));

const totals = computed(() =>
    quoteTotals(
        form.lines.map((line) => ({
            ...line,
            vat_rate: ratesById.value[line.vat_rate_id]?.rate ?? line.vat_rate ?? 0,
            vat_nature: ratesById.value[line.vat_rate_id]?.nature ?? line.vat_nature ?? null,
        })),
    ),
);

const rebillTotal = computed(() =>
    form.customer_id
        ? form.lines
              .filter((line) => line.type === 'item' && line.rebill)
              .reduce((sum, line) => sum + lineTotal(line) * (1 + Number(line.markup_percent || 0) / 100), 0)
        : 0,
);

// The customer of a linked order is the one the costs are charged to.
watch(
    () => form.order_id,
    (orderId) => {
        const order = props.orders.find((item) => item.value === orderId);

        if (order) {
            form.customer_id = order.organization_id;
        }
    },
);

// --- Supplier contacts -----------------------------------------------------------------
const contacts = ref([]);
const supplierLocations = ref([]);
const loadingSupplier = ref(false);

async function loadSupplier(organizationId, { applyDefaults }) {
    contacts.value = [];
    supplierLocations.value = [];

    if (!organizationId) {
        return;
    }

    loadingSupplier.value = true;

    try {
        const data = await http.get(route('sales.quotes.lookup.organization', organizationId));
        contacts.value = data.contacts;
        supplierLocations.value = data.locations;

        if (applyDefaults) {
            form.contact_id = data.contacts.find((contact) => contact.is_primary)?.value ?? null;
            form.payment_term_id = data.payment_term_id ?? form.payment_term_id;
        }
    } finally {
        loadingSupplier.value = false;
    }
}

loadSupplier(form.supplier_id, { applyDefaults: isNew.value && !form.contact_id });

watch(
    () => form.supplier_id,
    (id) => loadSupplier(id, { applyDefaults: true }),
);

const supplierSearch = ref('');
const quickSupplier = ref(false);
const quickSupplierName = ref('');
const contactDialog = ref(false);

function openQuickSupplier(name) {
    quickSupplierName.value = name;
    quickSupplier.value = true;
}

function supplierCreated(organization) {
    form.supplier_id = organization.value;
    supplierSearch.value = '';
}

function contactCreated(contact) {
    contacts.value = [...contacts.value, contact];
    form.contact_id = contact.value;
}

function submit() {
    // eslint-disable-next-line no-unused-vars
    const clean = ({ key, id, total, vat_rate, vat_nature, is_optional, is_selected, rebill_amount, rebilled_in, ...line }) => line;
    const transform = (data) => ({ ...data, lines: linesEditable.value ? data.lines.map(clean) : undefined });

    if (isNew.value) {
        form.transform(transform).post(route('purchases.orders.store'));
    } else {
        form.transform(transform).put(route('purchases.orders.update', props.purchaseOrder.id));
    }
}

const backUrl = computed(() => (isNew.value ? route('purchases.orders.index') : route('purchases.orders.show', props.purchaseOrder.id)));
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Nuovo ordine fornitore' : `Modifica ${purchaseOrder.number}`"
            :subtitle="isNew ? 'Il numero viene assegnato al salvataggio.' : purchaseOrder.supplier.name"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(backUrl)">{{ isNew ? 'Ordini fornitore' : 'Scheda' }}</v-btn>
            </template>
        </PageHeader>

        <v-form @submit.prevent="submit">
            <v-row>
                <v-col cols="12" lg="8">
                    <v-card class="mb-6" title="Fornitore">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="7">
                                    <v-autocomplete
                                        v-model="form.supplier_id"
                                        v-model:search="supplierSearch"
                                        :items="supplierItems"
                                        item-props
                                        label="Fornitore"
                                        :disabled="!linesEditable"
                                        :error-messages="form.errors.supplier_id"
                                        :autofocus="isNew && !form.supplier_id"
                                    >
                                        <template v-if="can('organizations.create') && linesEditable" #append>
                                            <v-btn
                                                icon="mdi-truck-plus-outline"
                                                variant="tonal"
                                                color="primary"
                                                title="Nuovo fornitore"
                                                @click="openQuickSupplier('')"
                                            />
                                        </template>
                                        <template #no-data>
                                            <v-list-item
                                                v-if="can('organizations.create') && supplierSearch"
                                                prepend-icon="mdi-plus"
                                                :title="`Crea «${supplierSearch}»`"
                                                subtitle="Nuovo fornitore nelle anagrafiche"
                                                @click="openQuickSupplier(supplierSearch)"
                                            />
                                            <v-list-item v-else title="Nessuna organizzazione trovata" />
                                        </template>
                                    </v-autocomplete>
                                </v-col>
                                <v-col cols="12" md="5">
                                    <v-select
                                        v-model="form.contact_id"
                                        :items="contacts"
                                        item-props
                                        label="Alla c.a. di"
                                        clearable
                                        :loading="loadingSupplier"
                                        :disabled="!form.supplier_id"
                                        :error-messages="form.errors.contact_id"
                                    >
                                        <template v-if="can('organizations.update') && form.supplier_id" #append>
                                            <v-btn
                                                icon="mdi-account-plus-outline"
                                                variant="tonal"
                                                color="primary"
                                                title="Nuovo contatto"
                                                @click="contactDialog = true"
                                            />
                                        </template>
                                    </v-select>
                                </v-col>
                                <v-col cols="12" sm="8">
                                    <v-text-field v-model="form.title" label="Oggetto" :error-messages="form.errors.title" />
                                </v-col>
                                <v-col cols="12" sm="4">
                                    <v-text-field
                                        v-model="form.supplier_reference"
                                        label="Rif. offerta fornitore"
                                        :error-messages="form.errors.supplier_reference"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Per chi acquisti" subtitle="Collega un ordine o un cliente per ribaltargli i costi">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-autocomplete
                                        v-model="form.order_id"
                                        :items="orders"
                                        item-props
                                        label="Ordine cliente"
                                        clearable
                                        :disabled="!linesEditable"
                                        :error-messages="form.errors.order_id"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-autocomplete
                                        v-model="form.customer_id"
                                        :items="customers"
                                        item-props
                                        label="Cliente a cui ribaltare i costi"
                                        clearable
                                        :disabled="!linesEditable || Boolean(form.order_id)"
                                        hint="Vuoto per un acquisto interno"
                                        persistent-hint
                                        :error-messages="form.errors.customer_id"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Righe">
                        <v-card-text>
                            <v-alert v-if="!linesEditable" type="info" variant="tonal">
                                Alcuni costi sono già in fattura al cliente: fornitore, cliente e righe non si modificano più. Puoi aggiornare date,
                                riferimenti e note.
                            </v-alert>
                            <template v-else>
                                <v-alert v-if="form.customer_id" type="info" variant="tonal" density="compact" class="mb-4">
                                    Spunta «Da ribaltare al cliente» sulle righe da addebitare, con l'eventuale ricarico. Le trovi poi nella fattura
                                    del cliente.
                                </v-alert>
                                <QuoteLinesEditor
                                    v-model="form.lines"
                                    :vat-rates="vatRates"
                                    :units="units"
                                    :errors="form.errors"
                                    :default-vat-rate-id="defaultVatRateId"
                                    :allow-optional="false"
                                    at-cost
                                    :rebillable="Boolean(form.customer_id)"
                                />
                            </template>
                        </v-card-text>
                    </v-card>

                    <v-card title="Note">
                        <v-card-text>
                            <v-textarea
                                v-model="form.notes"
                                label="Note per il fornitore"
                                hint="Compaiono nell'ordine"
                                persistent-hint
                                rows="2"
                                auto-grow
                                :error-messages="form.errors.notes"
                                class="mb-4"
                            />
                            <v-textarea
                                v-model="form.internal_notes"
                                label="Note interne"
                                hint="Solo per uso interno"
                                persistent-hint
                                rows="2"
                                auto-grow
                                :error-messages="form.errors.internal_notes"
                            />
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <div class="document-sidebar">
                        <QuoteTotalsCard v-if="linesEditable" :totals="totals" class="mb-2" />
                        <div v-if="rebillTotal" class="text-body-2 text-medium-emphasis mb-6 px-2">
                            Da ribaltare al cliente:
                            <strong>{{ formatMoney(rebillTotal) }}</strong>
                            + IVA
                        </div>
                        <div v-else class="mb-6" />

                        <v-card class="mb-6" title="Dettagli">
                            <v-card-text>
                                <v-text-field
                                    v-model="form.order_date"
                                    label="Data ordine"
                                    type="date"
                                    :error-messages="form.errors.order_date"
                                    class="mb-2"
                                />
                                <v-text-field
                                    v-model="form.expected_date"
                                    label="Consegna attesa"
                                    type="date"
                                    clearable
                                    :error-messages="form.errors.expected_date"
                                    class="mb-2"
                                />
                                <v-select
                                    v-model="form.payment_term_id"
                                    :items="paymentTerms"
                                    label="Pagamento"
                                    clearable
                                    :error-messages="form.errors.payment_term_id"
                                    class="mb-2"
                                />
                                <v-select v-model="form.owner_id" :items="owners" label="Responsabile" :error-messages="form.errors.owner_id" />
                            </v-card-text>
                        </v-card>

                        <div class="d-flex ga-2">
                            <v-btn variant="text" v-bind="link(backUrl)">Annulla</v-btn>
                            <v-spacer />
                            <v-btn
                                type="submit"
                                color="primary"
                                variant="flat"
                                size="large"
                                prepend-icon="mdi-content-save-outline"
                                :loading="form.processing"
                            >
                                {{ isNew ? 'Crea ordine' : 'Salva' }}
                            </v-btn>
                        </div>
                        <div v-if="Object.keys(form.errors).length" class="text-error text-body-2 mt-2">Controlla i campi evidenziati.</div>
                    </div>
                </v-col>
            </v-row>
        </v-form>

        <ContactDialog
            v-if="form.supplier_id"
            v-model="contactDialog"
            :organization-id="form.supplier_id"
            :locations="supplierLocations"
            @created="contactCreated"
        />

        <QuickOrganizationDialog
            v-model="quickSupplier"
            :types="organizationTypes"
            :payment-terms="paymentTerms"
            :initial-name="quickSupplierName"
            role="supplier"
            @created="supplierCreated"
        />
    </div>
</template>

<style scoped>
@media (min-width: 1280px) {
    .document-sidebar {
        position: sticky;
        top: 88px;
    }
}
</style>
