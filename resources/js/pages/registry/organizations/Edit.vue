<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import OrganizationForm from '@/components/registry/OrganizationForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    organization: { type: Object, required: true },
    types: { type: Array, required: true },
    paymentTerms: { type: Array, default: () => [] },
});

const { link } = useInertiaLink();

const fields = ['name', 'type', 'vat_number', 'tax_code', 'sdi_code', 'payment_term_id', 'email', 'pec', 'phone', 'website', 'notes'];
const form = useForm({
    ...Object.fromEntries(fields.map((field) => [field, props.organization[field] ?? ''])),
    split_payment: props.organization.split_payment ?? false,
    is_customer: props.organization.is_customer ?? true,
    is_supplier: props.organization.is_supplier ?? false,
});

function submit() {
    form.put(route('registry.organizations.update', props.organization.id));
}
</script>

<template>
    <div>
        <PageHeader :title="`Modifica: ${organization.name}`">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('registry.organizations.show', organization.id))">
                    Scheda
                </v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-form @submit.prevent="submit">
                <v-card-text>
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
