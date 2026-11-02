<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import QuoteLinesEditor from '@/components/sales/QuoteLinesEditor.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { http } from '@/utils/http';
import { quoteTotals } from '@/utils/quoteTotals';

const props = defineProps({
    /** null when creating. */
    quote: { type: Object, default: null },
    defaults: { type: Object, default: null },
    organizations: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    paymentTerms: { type: Array, required: true },
    units: { type: Array, required: true },
    /** Users who can own quotes; null when the user can't choose. */
    owners: { type: Array, default: null },
});

const { link } = useInertiaLink();
const isNew = computed(() => !props.quote);
const source = props.quote ?? props.defaults;

const form = useForm({
    organization_id: source.organization_id ?? null,
    contact_id: source.contact_id ?? null,
    owner_id: source.owner_id ?? null,
    title: source.title ?? '',
    issue_date: source.issue_date,
    valid_until: source.valid_until,
    payment_term_id: source.payment_term_id ?? null,
    intro: source.intro ?? '',
    terms: source.terms ?? '',
    internal_notes: source.internal_notes ?? '',
    lines: (props.quote?.lines ?? []).map((line) => ({ ...line, key: `line-${line.id}` })),
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

// --- Customer: contacts and default payment term ---------------------------------
const contacts = ref([]);
const loadingCustomer = ref(false);

async function loadCustomer(organizationId, { applyDefaults }) {
    contacts.value = [];

    if (!organizationId) {
        return;
    }

    loadingCustomer.value = true;

    try {
        const data = await http.get(route('sales.quotes.lookup.organization', organizationId));
        contacts.value = data.contacts;

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

function submit() {
    const transform = (data) => ({
        ...data,
        lines: data.lines.map(({ key, id, total, vat_rate, vat_nature, ...line }) => line), // eslint-disable-line no-unused-vars
    });

    if (isNew.value) {
        form.transform(transform).post(route('sales.quotes.store'));
    } else {
        form.transform(transform).put(route('sales.quotes.update', props.quote.id));
    }
}

const backUrl = computed(() => (isNew.value ? route('sales.quotes.index') : route('sales.quotes.show', props.quote.id)));
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Nuovo preventivo' : `Modifica ${quote.number}`"
            :subtitle="isNew ? 'Il numero viene assegnato al salvataggio.' : 'Bozza'"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(backUrl)">{{ isNew ? 'Preventivi' : 'Scheda' }}</v-btn>
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
                                        :items="organizations"
                                        item-props
                                        label="Cliente"
                                        :error-messages="form.errors.organization_id"
                                        :autofocus="isNew && !form.organization_id"
                                    />
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
                                        no-data-text="Nessun contatto"
                                        :error-messages="form.errors.contact_id"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field
                                        v-model="form.title"
                                        label="Oggetto"
                                        placeholder="Es. Fornitura e installazione impianto"
                                        :error-messages="form.errors.title"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Righe">
                        <v-card-text>
                            <QuoteLinesEditor
                                v-model="form.lines"
                                :vat-rates="vatRates"
                                :units="units"
                                :organization-id="form.organization_id"
                                :errors="form.errors"
                                :default-vat-rate-id="defaultVatRateId"
                            />
                        </v-card-text>
                    </v-card>

                    <v-card title="Testi">
                        <v-card-text>
                            <v-textarea
                                v-model="form.intro"
                                label="Introduzione"
                                hint="Testo prima delle righe, es. riferimenti alla richiesta del cliente"
                                persistent-hint
                                rows="2"
                                auto-grow
                                :error-messages="form.errors.intro"
                                class="mb-4"
                            />
                            <v-textarea v-model="form.terms" label="Condizioni" rows="3" auto-grow :error-messages="form.errors.terms" class="mb-4" />
                            <v-textarea
                                v-model="form.internal_notes"
                                label="Note interne"
                                hint="Non compaiono nel preventivo"
                                persistent-hint
                                rows="2"
                                auto-grow
                                :error-messages="form.errors.internal_notes"
                            />
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <div class="quote-sidebar">
                        <QuoteTotalsCard :totals="totals" class="mb-6" />

                        <v-card class="mb-6" title="Dettagli">
                            <v-card-text>
                                <v-text-field
                                    v-model="form.issue_date"
                                    label="Data"
                                    type="date"
                                    :error-messages="form.errors.issue_date"
                                    class="mb-2"
                                />
                                <v-text-field
                                    v-model="form.valid_until"
                                    label="Valido fino al"
                                    type="date"
                                    :error-messages="form.errors.valid_until"
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
                                <v-select
                                    v-if="owners"
                                    v-model="form.owner_id"
                                    :items="owners"
                                    label="Responsabile"
                                    :error-messages="form.errors.owner_id"
                                />
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
                                {{ isNew ? 'Crea preventivo' : 'Salva' }}
                            </v-btn>
                        </div>
                        <div v-if="Object.keys(form.errors).length" class="text-error text-body-2 mt-2">Controlla i campi evidenziati.</div>
                    </div>
                </v-col>
            </v-row>
        </v-form>
    </div>
</template>

<style scoped>
@media (min-width: 1280px) {
    .quote-sidebar {
        position: sticky;
        top: 88px;
    }
}
</style>
