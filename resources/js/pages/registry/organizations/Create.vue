<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import DocumentImportDialog from '@/components/DocumentImportDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import OrganizationForm from '@/components/registry/OrganizationForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { useToast } from '@/composables/useToast';
import { countries } from '@/utils/countries';
import { formatDate } from '@/utils/format';
import { http } from '@/utils/http';

const props = defineProps({
    /** { value, label, plural, index_route, create_route } */
    role: { type: Object, required: true },
    types: { type: Array, required: true },
    paymentTerms: { type: Array, default: () => [] },
    /** Fields read from a document by the AI. */
    defaults: { type: Object, default: null },
    review: { type: Object, default: null },
    canUseAi: { type: Boolean, default: false },
    /** «Compila» from the Registro Imprese (Openapi "Company" service switched on). */
    companyLookup: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const importDialog = ref(false);

const blankLocation = { address: '', postal_code: '', city: '', province: '', country: 'IT' };
const blankContact = { first_name: '', last_name: '', job_title: '', email: '', phone: '' };

const form = useForm({
    name: '',
    type: 'company',
    vat_number: '',
    tax_code: '',
    sdi_code: '',
    payment_term_id: null,
    split_payment: false,
    email: '',
    pec: '',
    phone: '',
    website: '',
    notes: '',
    ...(props.defaults ?? {}),
    role: props.role.value,
    location: { ...blankLocation, ...(props.defaults?.location ?? {}) },
    contact: { ...blankContact, ...(props.defaults?.contact ?? {}) },
});

const italian = computed(() => form.location.country === 'IT');

function submit() {
    form.post(route('registry.organizations.store'));
}

// --- Registro Imprese -------------------------------------------------------------------------
const toast = useToast();
const lookupLoading = ref(false);
/** { company, existing } of the last lookup. */
const registry = ref(null);

async function lookupCompany() {
    lookupLoading.value = true;

    try {
        const data = await http.get(route('registry.company-lookup', { role: props.role.value, code: form.vat_number || form.tax_code }));
        const { company } = data;

        for (const field of ['name', 'vat_number', 'tax_code', 'sdi_code', 'pec']) {
            if (company[field] && !(field === 'sdi_code' && form.type === 'public_body')) {
                form[field] = company[field];
            }
        }

        if (company.location?.address) {
            const { address, postal_code, city, province, country } = company.location;
            form.location = { ...form.location, address, postal_code: postal_code ?? '', city: city ?? '', province: province ?? '', country };
        }

        registry.value = data;
    } catch (error) {
        toast.error(error.errors?.code?.[0] ?? error.message);
    } finally {
        lookupLoading.value = false;
    }
}

const registryDetails = computed(() => {
    const company = registry.value?.company;

    return company
        ? [company.legal_form, company.rea && `REA ${company.rea}`, company.ateco && `ATECO ${company.ateco}`].filter(Boolean).join(' · ')
        : '';
});
</script>

<template>
    <div>
        <PageHeader
            :title="`Nuovo ${role.label.toLowerCase()}`"
            subtitle="Sede principale e referente sono facoltativi: puoi aggiungerli anche dopo."
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route(role.index_route))">{{ role.plural }}</v-btn>
                <v-btn v-if="canUseAi && !review" variant="tonal" prepend-icon="mdi-file-upload-outline" @click="importDialog = true">
                    Compila da documento
                </v-btn>
            </template>
        </PageHeader>

        <v-alert
            v-if="review"
            :type="review.warnings.length || review.existing ? 'warning' : 'success'"
            variant="tonal"
            class="mb-6"
            title="Dati letti dal documento con l'AI"
        >
            <div class="text-body-2">
                Controlla i campi prima di salvare.
                <template v-if="review.model">
                    Modello {{ review.model }}
                    <template v-if="review.cost !== null">· costo {{ `$ ${review.cost.toFixed(4)}` }}</template>
                    .
                </template>
            </div>
            <div v-if="review.existing" class="text-body-2 mt-2">
                Con questa partita IVA o codice fiscale esiste già
                <a v-bind="link(route('registry.organizations.show', review.existing.id))">{{ review.existing.name }}</a>
                ({{ review.existing.role }}).
            </div>
            <ul v-if="review.warnings.length" class="ml-4 mt-2 text-body-2">
                <li v-for="(warning, index) in review.warnings" :key="index">{{ warning }}</li>
            </ul>
        </v-alert>

        <v-alert
            v-if="registry"
            :type="registry.company.ceased || registry.existing ? 'warning' : 'success'"
            variant="tonal"
            class="mb-6"
            title="Dati compilati dal Registro Imprese"
            closable
            @click:close="registry = null"
        >
            <div class="text-body-2">{{ registryDetails }}</div>
            <div v-if="registry.company.ceased" class="text-body-2 mt-2">
                L'azienda risulta cessata{{ registry.company.ceased_on ? ` dal ${formatDate(registry.company.ceased_on)}` : '' }}.
            </div>
            <div v-else-if="registry.company.status && registry.company.status !== 'ATTIVA'" class="text-body-2 mt-2">
                Stato: {{ registry.company.status_label }}.
            </div>
            <div v-if="registry.existing" class="text-body-2 mt-2">
                Con questa partita IVA o codice fiscale esiste già
                <a v-bind="link(route('registry.organizations.show', registry.existing.id))">{{ registry.existing.name }}</a>
                ({{ registry.existing.role }}).
            </div>
        </v-alert>

        <v-row>
            <v-col cols="12" :lg="review?.file_url ? 8 : 12">
                <v-card>
                    <v-form @submit.prevent="submit">
                        <v-card-text>
                            <OrganizationForm
                                v-model:form="form"
                                :types="types"
                                :payment-terms="paymentTerms"
                                :lookup="companyLookup"
                                :lookup-loading="lookupLoading"
                                @lookup="lookupCompany"
                            />

                            <div class="text-subtitle-1 font-weight-medium mt-6 mb-3">Sede principale</div>
                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="form.location.address"
                                        label="Indirizzo"
                                        :error-messages="form.errors['location.address']"
                                    />
                                </v-col>
                                <v-col cols="4" md="2">
                                    <v-text-field
                                        v-model="form.location.postal_code"
                                        label="CAP"
                                        :error-messages="form.errors['location.postal_code']"
                                    />
                                </v-col>
                                <v-col cols="8" md="4">
                                    <v-text-field v-model="form.location.city" label="Città" :error-messages="form.errors['location.city']" />
                                </v-col>
                                <v-col v-if="italian" cols="4" md="2">
                                    <v-text-field
                                        v-model="form.location.province"
                                        label="Prov."
                                        maxlength="2"
                                        :error-messages="form.errors['location.province']"
                                    />
                                </v-col>
                                <v-col cols="8" md="4">
                                    <v-autocomplete
                                        v-model="form.location.country"
                                        :items="countries"
                                        label="Paese"
                                        :error-messages="form.errors['location.country']"
                                    />
                                </v-col>
                            </v-row>

                            <div class="text-subtitle-1 font-weight-medium mt-4 mb-3">Referente</div>
                            <v-row dense>
                                <v-col cols="12" sm="6" md="4">
                                    <v-text-field
                                        v-model="form.contact.first_name"
                                        label="Nome"
                                        :error-messages="form.errors['contact.first_name']"
                                    />
                                </v-col>
                                <v-col cols="12" sm="6" md="4">
                                    <v-text-field
                                        v-model="form.contact.last_name"
                                        label="Cognome"
                                        :error-messages="form.errors['contact.last_name']"
                                    />
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field v-model="form.contact.job_title" label="Ruolo" :error-messages="form.errors['contact.job_title']" />
                                </v-col>
                                <v-col cols="12" sm="6">
                                    <v-text-field
                                        v-model="form.contact.email"
                                        label="Email"
                                        type="email"
                                        :error-messages="form.errors['contact.email']"
                                    />
                                </v-col>
                                <v-col cols="12" sm="6">
                                    <v-text-field v-model="form.contact.phone" label="Telefono" :error-messages="form.errors['contact.phone']" />
                                </v-col>
                            </v-row>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4">
                            <v-spacer />
                            <v-btn variant="text" v-bind="link(route(role.index_route))">Annulla</v-btn>
                            <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Crea {{ role.label.toLowerCase() }}</v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card>
            </v-col>
            <v-col v-if="review?.file_url" cols="12" lg="4">
                <v-card title="Documento originale" :subtitle="review.file_name" class="document-sidebar">
                    <v-img v-if="review.file_mime?.startsWith('image/')" :src="review.file_url" max-height="700" contain />
                    <iframe v-else :src="review.file_url" title="Documento" style="width: 100%; height: 700px; border: 0" />
                </v-card>
            </v-col>
        </v-row>

        <DocumentImportDialog v-model="importDialog" :kind="role.value" />
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
