<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { countries } from '@/utils/countries';

/**
 * Creates a customer without leaving the current page (e.g. the quote editor).
 * Emits "created" with the new organization as an option ({ value, title, subtitle }).
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    types: { type: Array, required: true },
    paymentTerms: { type: Array, default: () => [] },
    /** Prefilled name, e.g. what was typed in the customer search. */
    initialName: { type: String, default: '' },
    /** 'customer' or 'supplier'. */
    role: { type: String, default: 'customer' },
});

const emit = defineEmits(['created']);

const blank = () => ({
    name: '',
    type: 'company',
    vat_number: '',
    tax_code: '',
    sdi_code: '',
    pec: '',
    email: '',
    phone: '',
    payment_term_id: null,
    is_customer: props.role === 'customer',
    is_supplier: props.role === 'supplier',
    location: { address: '', postal_code: '', city: '', province: '', country: 'IT' },
    contact: { first_name: '', last_name: '', job_title: '', email: '', phone: '' },
});

const form = useForm(blank());
const showLocation = ref(true);
const showContact = ref(false);
const italian = computed(() => form.location.country === 'IT');

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.defaults({ ...blank(), name: props.initialName ?? '' });
    form.reset();
    form.clearErrors();
});

function submit() {
    form.transform((data) => ({
        ...data,
        location: showLocation.value ? data.location : { country: 'IT' },
        contact: showContact.value ? data.contact : null,
    })).post(route('registry.organizations.quick-store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const created = page.flash?.createdOrganization;

            if (created) {
                emit('created', created);
            }

            open.value = false;
        },
    });
}
</script>

<template>
    <v-dialog v-model="open" max-width="760" scrollable>
        <v-card
            :title="role === 'supplier' ? 'Nuovo fornitore' : 'Nuovo cliente'"
            subtitle="Viene aggiunto alle anagrafiche e selezionato nel documento."
        >
            <v-divider />
            <v-card-text>
                <v-form id="quick-organization-form" @submit.prevent="submit">
                    <v-row dense>
                        <v-col cols="12" md="8">
                            <v-text-field v-model="form.name" label="Ragione sociale / Nome" :error-messages="form.errors.name" autofocus />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select v-model="form.type" :items="types" label="Tipo" :error-messages="form.errors.type" />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field v-model="form.vat_number" label="Partita IVA" :error-messages="form.errors.vat_number" />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field v-model="form.tax_code" label="Codice fiscale" :error-messages="form.errors.tax_code" />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field v-model="form.sdi_code" label="Codice SDI" maxlength="7" :error-messages="form.errors.sdi_code" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.email" label="Email" type="email" :error-messages="form.errors.email" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.pec" label="PEC" type="email" :error-messages="form.errors.pec" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.phone" label="Telefono" type="tel" :error-messages="form.errors.phone" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="form.payment_term_id"
                                :items="paymentTerms"
                                label="Condizione di pagamento"
                                clearable
                                :error-messages="form.errors.payment_term_id"
                            />
                        </v-col>
                    </v-row>

                    <v-checkbox v-model="showLocation" label="Aggiungi la sede legale" hide-details class="mt-2" />
                    <v-row v-if="showLocation" dense>
                        <v-col cols="12">
                            <v-text-field v-model="form.location.address" label="Indirizzo" :error-messages="form.errors['location.address']" />
                        </v-col>
                        <v-col cols="4" md="2">
                            <v-text-field
                                v-model="form.location.postal_code"
                                label="CAP"
                                :maxlength="italian ? 5 : 16"
                                :error-messages="form.errors['location.postal_code']"
                            />
                        </v-col>
                        <v-col cols="8" md="5">
                            <v-text-field v-model="form.location.city" label="Città" :error-messages="form.errors['location.city']" />
                        </v-col>
                        <v-col cols="4" md="2">
                            <v-text-field
                                v-model="form.location.province"
                                label="Prov."
                                :maxlength="italian ? 2 : 8"
                                :error-messages="form.errors['location.province']"
                                @update:model-value="(value) => (form.location.province = (value ?? '').toUpperCase())"
                            />
                        </v-col>
                        <v-col cols="8" md="3">
                            <v-autocomplete
                                v-model="form.location.country"
                                :items="countries"
                                label="Nazione"
                                :error-messages="form.errors['location.country']"
                            />
                        </v-col>
                    </v-row>

                    <v-checkbox v-model="showContact" label="Aggiungi un referente" hide-details />
                    <v-row v-if="showContact" dense>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.contact.first_name" label="Nome" :error-messages="form.errors['contact.first_name']" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.contact.last_name" label="Cognome" :error-messages="form.errors['contact.last_name']" />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field v-model="form.contact.job_title" label="Ruolo" :error-messages="form.errors['contact.job_title']" />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="form.contact.email"
                                label="Email referente"
                                type="email"
                                :error-messages="form.errors['contact.email']"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="form.contact.phone"
                                label="Telefono referente"
                                type="tel"
                                :error-messages="form.errors['contact.phone']"
                            />
                        </v-col>
                    </v-row>
                </v-form>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn type="submit" form="quick-organization-form" color="primary" variant="flat" :loading="form.processing">Crea cliente</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
