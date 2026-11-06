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
import { http } from '@/utils/http';
import { quoteTotals } from '@/utils/quoteTotals';

const props = defineProps({
    /** null when creating. */
    order: { type: Object, default: null },
    defaults: { type: Object, default: null },
    organizations: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    paymentTerms: { type: Array, required: true },
    units: { type: Array, required: true },
    organizationTypes: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const isNew = computed(() => !props.order);
const source = props.order ?? props.defaults;
/** Customer and lines are frozen once something has been invoiced. */
const linesEditable = computed(() => isNew.value || Boolean(props.order.can?.edit_lines));

const form = useForm({
    organization_id: source.organization_id ?? null,
    contact_id: source.contact_id ?? null,
    owner_id: source.owner_id ?? null,
    title: source.title ?? '',
    order_date: source.order_date,
    delivery_date: source.delivery_date ?? null,
    customer_reference: source.customer_reference ?? '',
    customer_reference_date: source.customer_reference_date ?? null,
    payment_term_id: source.payment_term_id ?? null,
    notes: source.notes ?? '',
    internal_notes: source.internal_notes ?? '',
    lines: (props.order?.lines ?? []).map((line) => ({ ...line, key: `line-${line.id}` })),
});

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

// --- Customer: contacts, locations and default payment term ------------------------
const contacts = ref([]);
const customerLocations = ref([]);
const loadingCustomer = ref(false);

async function loadCustomer(organizationId, { applyDefaults }) {
    contacts.value = [];
    customerLocations.value = [];

    if (!organizationId) {
        return;
    }

    loadingCustomer.value = true;

    try {
        const data = await http.get(route('sales.quotes.lookup.organization', organizationId));
        contacts.value = data.contacts;
        customerLocations.value = data.locations;

        if (applyDefaults) {
            form.contact_id = data.contacts.find((contact) => contact.is_primary)?.value ?? null;

            if (data.payment_term_id) {
                form.payment_term_id = data.payment_term_id;
            }
        }
    } finally {
        loadingCustomer.value = false;
    }
}

loadCustomer(form.organization_id, { applyDefaults: isNew.value && !form.contact_id });

watch(
    () => form.organization_id,
    (id) => loadCustomer(id, { applyDefaults: true }),
);

const customerSearch = ref('');
const quickCustomer = ref(false);
const quickCustomerName = ref('');
const contactDialog = ref(false);

function openQuickCustomer(name) {
    quickCustomerName.value = name;
    quickCustomer.value = true;
}

function customerCreated(organization) {
    form.organization_id = organization.value;
    customerSearch.value = '';
}

function contactCreated(contact) {
    contacts.value = [...contacts.value, contact];
    form.contact_id = contact.value;
}

function submit() {
    // eslint-disable-next-line no-unused-vars
    const clean = ({ key, id, total, vat_rate, vat_nature, quantity_invoiced, quantity_remaining, ...line }) => line;
    const transform = (data) => ({ ...data, lines: linesEditable.value ? data.lines.map(clean) : undefined });

    if (isNew.value) {
        form.transform(transform).post(route('sales.orders.store'));
    } else {
        form.transform(transform).put(route('sales.orders.update', props.order.id));
    }
}

const backUrl = computed(() => (isNew.value ? route('sales.orders.index') : route('sales.orders.show', props.order.id)));
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Nuovo ordine' : `Modifica ${order.number}`"
            :subtitle="isNew ? 'Il numero viene assegnato al salvataggio.' : order.customer.name"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(backUrl)">{{ isNew ? 'Ordini' : 'Scheda' }}</v-btn>
            </template>
        </PageHeader>

        <v-form @submit.prevent="submit">
            <v-row>
                <v-col cols="12" lg="8">
                    <v-card class="mb-6" title="Cliente">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="7">
                                    <v-autocomplete
                                        v-model="form.organization_id"
                                        v-model:search="customerSearch"
                                        :items="organizations"
                                        item-props
                                        label="Cliente"
                                        :disabled="!linesEditable"
                                        :error-messages="form.errors.organization_id"
                                        :autofocus="isNew && !form.organization_id"
                                    >
                                        <template v-if="can('organizations.create') && linesEditable" #append>
                                            <v-btn
                                                icon="mdi-account-plus-outline"
                                                variant="tonal"
                                                color="primary"
                                                title="Nuovo cliente"
                                                @click="openQuickCustomer('')"
                                            />
                                        </template>
                                        <template #no-data>
                                            <v-list-item
                                                v-if="can('organizations.create') && customerSearch"
                                                prepend-icon="mdi-plus"
                                                :title="`Crea «${customerSearch}»`"
                                                subtitle="Nuovo cliente nelle anagrafiche"
                                                @click="openQuickCustomer(customerSearch)"
                                            />
                                            <v-list-item v-else title="Nessun cliente trovato" />
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
                                        :loading="loadingCustomer"
                                        :disabled="!form.organization_id"
                                        :error-messages="form.errors.contact_id"
                                    >
                                        <template v-if="can('organizations.update') && form.organization_id" #append>
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
                                <v-col cols="12">
                                    <v-text-field
                                        v-model="form.title"
                                        label="Oggetto"
                                        placeholder="Es. Fornitura e installazione impianto"
                                        :error-messages="form.errors.title"
                                    />
                                </v-col>
                                <v-col cols="12" sm="7">
                                    <v-text-field
                                        v-model="form.customer_reference"
                                        label="Riferimento ordine del cliente"
                                        hint="Numero d'ordine o protocollo del cliente: viene riportato in fattura"
                                        persistent-hint
                                        maxlength="20"
                                        :error-messages="form.errors.customer_reference"
                                    />
                                </v-col>
                                <v-col cols="12" sm="5">
                                    <v-text-field
                                        v-model="form.customer_reference_date"
                                        label="Del"
                                        type="date"
                                        clearable
                                        :error-messages="form.errors.customer_reference_date"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Righe">
                        <v-card-text>
                            <v-alert v-if="!linesEditable" type="info" variant="tonal">
                                L'ordine è già stato fatturato, anche solo in parte: cliente e righe non si modificano più. Puoi aggiornare date,
                                riferimenti e note.
                            </v-alert>
                            <QuoteLinesEditor
                                v-else
                                v-model="form.lines"
                                :vat-rates="vatRates"
                                :units="units"
                                :organization-id="form.organization_id"
                                :errors="form.errors"
                                :default-vat-rate-id="defaultVatRateId"
                                :allow-optional="false"
                                allow-packages
                            />
                        </v-card-text>
                    </v-card>

                    <v-card title="Note">
                        <v-card-text>
                            <v-textarea
                                v-model="form.notes"
                                label="Note"
                                hint="Compaiono nella conferma d'ordine"
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
                        <QuoteTotalsCard v-if="linesEditable" :totals="totals" class="mb-6" />

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
                                    v-model="form.delivery_date"
                                    label="Consegna prevista"
                                    type="date"
                                    clearable
                                    :error-messages="form.errors.delivery_date"
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
            v-if="form.organization_id"
            v-model="contactDialog"
            :organization-id="form.organization_id"
            :locations="customerLocations"
            @created="contactCreated"
        />

        <QuickOrganizationDialog
            v-model="quickCustomer"
            :types="organizationTypes"
            :payment-terms="paymentTerms"
            :initial-name="quickCustomerName"
            @created="customerCreated"
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
