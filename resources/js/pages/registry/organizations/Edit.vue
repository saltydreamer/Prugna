<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import OrganizationForm from '@/components/registry/OrganizationForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    organization: { type: Object, required: true },
    role: { type: Object, required: true },
    /** False once quotes, orders, invoices or supplier orders refer to the organization. */
    canChangeRole: { type: Boolean, default: false },
    types: { type: Array, required: true },
    paymentTerms: { type: Array, default: () => [] },
});

const { link } = useInertiaLink();

const fields = ['name', 'type', 'vat_number', 'tax_code', 'sdi_code', 'payment_term_id', 'email', 'pec', 'phone', 'website', 'notes'];
const form = useForm({
    ...Object.fromEntries(fields.map((field) => [field, props.organization[field] ?? ''])),
    split_payment: props.organization.split_payment ?? false,
    role: props.organization.role,
});

function submit() {
    form.transform((data) => ({ ...data, role: props.canChangeRole ? data.role : undefined })).put(
        route('registry.organizations.update', props.organization.id),
    );
}
</script>

<template>
    <div>
        <PageHeader :title="`Modifica: ${organization.name}`" :subtitle="role.label">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('registry.organizations.show', organization.id))">
                    Scheda
                </v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-form @submit.prevent="submit">
                <v-card-text>
                    <v-alert v-if="canChangeRole" type="info" variant="tonal" density="compact" class="mb-6">
                        <div class="d-flex flex-wrap align-center ga-4">
                            <span>Inserito nell'anagrafica sbagliata? Puoi spostarlo finché non ha documenti.</span>
                            <v-btn-toggle v-model="form.role" mandatory density="compact" color="primary" variant="outlined">
                                <v-btn value="customer">Cliente</v-btn>
                                <v-btn value="supplier">Fornitore</v-btn>
                            </v-btn-toggle>
                        </div>
                    </v-alert>
                    <OrganizationForm v-model:form="form" :types="types" :payment-terms="paymentTerms" />
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-spacer />
                    <v-btn variant="text" v-bind="link(route('registry.organizations.show', organization.id))">Annulla</v-btn>
                    <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
                </v-card-actions>
            </v-form>
        </v-card>
    </div>
</template>
