<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import InvoiceTotalsCard from '@/components/sales/InvoiceTotalsCard.vue';
import QuoteLinesEditor from '@/components/sales/QuoteLinesEditor.vue';
import ContactDialog from '@/components/registry/ContactDialog.vue';
import QuickOrganizationDialog from '@/components/registry/QuickOrganizationDialog.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { http } from '@/utils/http';
import { invoiceTotals } from '@/utils/invoiceTotals';

const props = defineProps({
    /** null when creating. */
    invoice: { type: Object, default: null },
    defaults: { type: Object, default: null },
    organizations: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    paymentTerms: { type: Array, required: true },
    units: { type: Array, required: true },
    organizationTypes: { type: Array, default: () => [] },
    fiscalSettings: { type: Object, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const isNew = computed(() => !props.invoice);
const source = props.invoice ?? props.defaults;

const form = useForm({
    type: source.type ?? 'invoice',
    organization_id: source.organization_id ?? null,
    contact_id: source.contact_id ?? null,
    title: source.title ?? '',
    issue_date: source.issue_date,
    payment_term_id: source.payment_term_id ?? null,
    customer_reference: source.customer_reference ?? '',
    customer_reference_date: source.customer_reference_date ?? null,
    cig: source.cig ?? '',
    cup: source.cup ?? '',
    split_payment: Boolean(source.split_payment),
    apply_withholding: Boolean(source.apply_withholding),
    apply_pension: Boolean(source.apply_pension),
    notes: source.notes ?? '',
    internal_notes: source.internal_notes ?? '',
    lines: (props.invoice?.lines ?? []).map((line) => ({ ...line, key: `line-${line.id}` })),
});

const typeLabel = computed(() => ({ invoice: 'fattura', deposit: 'fattura di acconto', credit_note: 'nota di credito' })[form.type]);
const isFlatRate = computed(() => ['RF02', 'RF19'].includes(props.fiscalSettings.regime));

const defaultVatRateId = computed(() => {
    if (isFlatRate.value) {
        return props.vatRates.find((rate) => rate.nature === 'N2.2')?.id ?? null;
    }

    return props.vatRates.find((rate) => rate.is_default)?.id ?? props.vatRates[0]?.id ?? null;
});
const ratesById = computed(() => Object.fromEntries(props.vatRates.map((rate) => [rate.id, rate])));

const fiscal = computed(() => ({
    ...form.data(),
    pension_rate: props.fiscalSettings.pension_rate,
    pension_vat_rate: props.fiscalSettings.pension_vat_rate,
    pension_vat_nature: props.fiscalSettings.pension_vat_nature,
    pension_withholding: props.fiscalSettings.pension_withholding,
    withholding_rate: props.fiscalSettings.withholding_rate,
    stamp_duty_enabled: props.fiscalSettings.stamp_duty_enabled,
    stamp_duty_charge_customer: props.fiscalSettings.stamp_duty_charge_customer,
}));

const totals = computed(() =>
    invoiceTotals(
        form.lines.map((line) => ({
            ...line,
            vat_rate: ratesById.value[line.vat_rate_id]?.rate ?? line.vat_rate ?? 0,
            vat_nature: ratesById.value[line.vat_rate_id]?.nature ?? line.vat_nature ?? null,
        })),
        fiscal.value,
    ),
);

const pensionLabel = computed(() => (props.fiscalSettings.pension_type === 'TC22' ? 'Rivalsa INPS' : 'Contributo cassa'));

// --- Customer -------------------------------------------------------------------------
const contacts = ref([]);
const customerLocations = ref([]);
const loadingCustomer = ref(false);
const showPublicFields = ref(Boolean(source.cig || source.cup));

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
            form.payment_term_id = data.payment_term_id ?? form.payment_term_id;
            form.split_payment = data.invoice_defaults.split_payment;
            form.apply_withholding = data.invoice_defaults.apply_withholding;
            form.apply_pension = data.invoice_defaults.apply_pension;
            showPublicFields.value = showPublicFields.value || data.invoice_defaults.split_payment;
        }
    } finally {
        loadingCustomer.value = false;
    }
}

loadCustomer(form.organization_id, { applyDefaults: isNew.value });

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
    const clean = ({ key, id, total, vat_rate, vat_nature, is_optional, is_selected, ...line }) => line;
    const transform = (data) => ({ ...data, type: isNew.value ? data.type : undefined, lines: data.lines.map(clean) });

    if (isNew.value) {
        form.transform(transform).post(route('sales.invoices.store'));
    } else {
        form.transform(transform).put(route('sales.invoices.update', props.invoice.id));
    }
}

const backUrl = computed(() => (isNew.value ? route('sales.invoices.index') : route('sales.invoices.show', props.invoice.id)));
</script>

<template>
    <div>
        <PageHeader :title="isNew ? 'Nuova fattura' : `Modifica bozza di ${typeLabel}`" subtitle="Il numero viene assegnato all'emissione.">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(backUrl)">{{ isNew ? 'Fatture' : 'Scheda' }}</v-btn>
            </template>
        </PageHeader>

        <v-form @submit.prevent="submit">
            <v-row>
                <v-col cols="12" lg="8">
                    <v-card class="mb-6" title="Cliente">
                        <v-card-text>
                            <v-btn-toggle
                                v-if="isNew"
                                v-model="form.type"
                                mandatory
                                color="primary"
                                variant="outlined"
                                density="compact"
                                class="mb-4"
                            >
                                <v-btn value="invoice">Fattura</v-btn>
                                <v-btn value="deposit">Fattura di acconto</v-btn>
                            </v-btn-toggle>
                            <v-row dense>
                                <v-col cols="12" md="7">
                                    <v-autocomplete
                                        v-model="form.organization_id"
                                        v-model:search="customerSearch"
                                        :items="organizations"
                                        item-props
                                        label="Cliente"
                                        :disabled="form.type === 'credit_note'"
                                        :error-messages="form.errors.organization_id"
                                        :autofocus="isNew && !form.organization_id"
                                    >
                                        <template v-if="can('organizations.create') && form.type !== 'credit_note'" #append>
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
                                        label="Causale / oggetto"
                                        hint="Compare in fattura e nella causale dell'XML"
                                        persistent-hint
                                        :error-messages="form.errors.title"
                                    />
                                </v-col>
                                <v-col cols="12" sm="7">
                                    <v-text-field
                                        v-model="form.customer_reference"
                                        label="Riferimento ordine del cliente"
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
                                <template v-if="showPublicFields">
                                    <v-col cols="12" sm="6">
                                        <v-text-field v-model="form.cig" label="CIG" maxlength="15" :error-messages="form.errors.cig" />
                                    </v-col>
                                    <v-col cols="12" sm="6">
                                        <v-text-field v-model="form.cup" label="CUP" maxlength="15" :error-messages="form.errors.cup" />
                                    </v-col>
                                </template>
                                <v-col v-else cols="12">
                                    <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="showPublicFields = true">
                                        CIG / CUP (Pubblica Amministrazione)
                                    </v-btn>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Righe">
                        <v-card-text>
                            <v-alert v-if="isFlatRate" type="info" variant="tonal" density="compact" class="mb-4">
                                Regime forfettario: usa l'aliquota «Non soggetto (forfettario)». Il bollo da 2 € si aggiunge da solo sopra 77,47 €.
                            </v-alert>
                            <QuoteLinesEditor
                                v-model="form.lines"
                                :vat-rates="vatRates"
                                :units="units"
                                :organization-id="form.organization_id"
                                :errors="form.errors"
                                :default-vat-rate-id="defaultVatRateId"
                                :allow-optional="false"
                            />
                        </v-card-text>
                    </v-card>

                    <v-card title="Note">
                        <v-card-text>
                            <v-textarea
                                v-model="form.notes"
                                label="Note in fattura"
                                hint="Es. riferimenti, ringraziamenti. Il default si imposta in Impostazioni → Fiscale."
                                persistent-hint
                                rows="2"
                                auto-grow
                                :error-messages="form.errors.notes"
                                class="mb-4"
                            />
                            <v-textarea
                                v-model="form.internal_notes"
                                label="Note interne"
                                hint="Non compaiono in fattura"
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
                        <InvoiceTotalsCard
                            :totals="totals"
                            :invoice="{
                                ...form.data(),
                                pension_rate: fiscalSettings.pension_rate,
                                withholding_rate: fiscalSettings.withholding_rate,
                            }"
                            :pension-label="pensionLabel"
                            class="mb-6"
                        />

                        <v-card class="mb-6" title="Dettagli">
                            <v-card-text>
                                <v-text-field
                                    v-model="form.issue_date"
                                    label="Data fattura"
                                    type="date"
                                    :error-messages="form.errors.issue_date"
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
                                <v-switch
                                    v-if="fiscalSettings.withholding_enabled || form.apply_withholding"
                                    v-model="form.apply_withholding"
                                    :label="`Ritenuta d'acconto ${fiscalSettings.withholding_rate}%`"
                                    color="primary"
                                    hide-details
                                />
                                <v-switch
                                    v-if="fiscalSettings.pension_enabled || form.apply_pension"
                                    v-model="form.apply_pension"
                                    :label="`${pensionLabel} ${fiscalSettings.pension_rate}%`"
                                    color="primary"
                                    hide-details
                                />
                                <v-switch v-model="form.split_payment" label="Scissione dei pagamenti (split payment)" color="primary" hide-details />
                                <div class="text-caption text-medium-emphasis mt-2">Regime: {{ fiscalSettings.regime_label }}</div>
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
                                Salva bozza
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
