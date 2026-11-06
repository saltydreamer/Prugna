<script setup>
/**
 * Fields shared by the create / edit organization pages.
 * `v-model:form` is an Inertia useForm() instance owned by the page.
 */
const form = defineModel('form', { type: Object, required: true });

defineProps({
    types: { type: Array, required: true },
    paymentTerms: { type: Array, default: () => [] },
    /** «Compila» next to the VAT number: fills the form from the Registro Imprese (Openapi "Company"). */
    lookup: { type: Boolean, default: false },
    lookupLoading: { type: Boolean, default: false },
});

const emit = defineEmits(['lookup']);
</script>

<template>
    <div>
        <v-row>
            <v-col cols="12" md="8">
                <v-text-field v-model="form.name" label="Ragione sociale / Denominazione" :error-messages="form.errors.name" autofocus />
            </v-col>
            <v-col cols="12" md="4">
                <v-select v-model="form.type" :items="types" label="Tipo" :error-messages="form.errors.type" />
            </v-col>
        </v-row>

        <div class="text-subtitle-1 font-weight-medium mt-2 mb-3">Dati fiscali</div>
        <v-row>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="form.vat_number"
                    label="Partita IVA"
                    :hint="
                        lookup
                            ? '«Compila» cerca l\'azienda nel Registro Imprese (anche dal codice fiscale) e riempie dati fiscali, PEC e sede legale.'
                            : '11 cifre per le partite IVA italiane, con prefisso del paese per quelle estere (es. DE123456789).'
                    "
                    :error-messages="form.errors.vat_number"
                >
                    <template v-if="lookup" #append-inner>
                        <v-btn
                            size="small"
                            variant="tonal"
                            color="primary"
                            prepend-icon="mdi-cloud-download-outline"
                            :loading="lookupLoading"
                            :disabled="!form.vat_number && !form.tax_code"
                            title="Compila dal Registro Imprese"
                            @click="emit('lookup')"
                        >
                            Compila
                        </v-btn>
                    </template>
                </v-text-field>
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field v-model="form.tax_code" label="Codice fiscale" :error-messages="form.errors.tax_code" />
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="form.sdi_code"
                    :label="form.type === 'public_body' ? 'Codice univoco ufficio (PA)' : 'Codice destinatario SDI'"
                    :maxlength="form.type === 'public_body' ? 6 : 7"
                    :hint="form.type === 'public_body' ? 'Codice IPA dell\'ufficio, 6 caratteri.' : 'Per la fatturazione elettronica (7 caratteri).'"
                    :error-messages="form.errors.sdi_code"
                />
            </v-col>
        </v-row>

        <v-row>
            <v-col cols="12" md="4" order-md="2">
                <v-switch
                    v-model="form.split_payment"
                    label="Split payment"
                    hint="Scissione dei pagamenti (art. 17-ter): il cliente versa l'IVA allo Stato"
                    persistent-hint
                />
            </v-col>
            <v-col cols="12" md="8">
                <v-select
                    v-model="form.payment_term_id"
                    :items="paymentTerms"
                    label="Condizione di pagamento predefinita"
                    hint="Proposta in automatico nei preventivi e nei documenti del cliente"
                    persistent-hint
                    clearable
                    :error-messages="form.errors.payment_term_id"
                />
            </v-col>
        </v-row>

        <div class="text-subtitle-1 font-weight-medium mt-2 mb-3">Recapiti</div>
        <v-row>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="form.email"
                    label="Email"
                    type="email"
                    prepend-inner-icon="mdi-email-outline"
                    :error-messages="form.errors.email"
                />
            </v-col>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="form.pec"
                    label="PEC"
                    type="email"
                    prepend-inner-icon="mdi-email-lock-outline"
                    :error-messages="form.errors.pec"
                />
            </v-col>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="form.phone"
                    label="Telefono"
                    type="tel"
                    prepend-inner-icon="mdi-phone-outline"
                    :error-messages="form.errors.phone"
                />
            </v-col>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="form.website"
                    label="Sito web"
                    prepend-inner-icon="mdi-web"
                    placeholder="www.esempio.it"
                    :error-messages="form.errors.website"
                />
            </v-col>
            <v-col cols="12">
                <v-textarea v-model="form.notes" label="Note" rows="3" auto-grow counter="5000" :error-messages="form.errors.notes" />
            </v-col>
        </v-row>
    </div>
</template>
