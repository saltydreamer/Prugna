<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import OrganizationForm from '@/components/registry/OrganizationForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

defineProps({
    types: { type: Array, required: true },
});

const { link } = useInertiaLink();

const form = useForm({
    name: '',
    type: 'company',
    vat_number: '',
    tax_code: '',
    sdi_code: '',
    email: '',
    pec: '',
    phone: '',
    website: '',
    notes: '',
});

function submit() {
    form.post(route('registry.organizations.store'));
}
</script>

<template>
    <div>
        <PageHeader title="Nuova organizzazione" subtitle="Dopo il salvataggio potrai aggiungere sedi e contatti.">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('registry.organizations.index'))">Organizzazioni</v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-form @submit.prevent="submit">
                <v-card-text>
                    <OrganizationForm v-model:form="form" :types="types" />
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-spacer />
                    <v-btn variant="text" v-bind="link(route('registry.organizations.index'))">Annulla</v-btn>
                    <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Crea organizzazione</v-btn>
                </v-card-actions>
            </v-form>
        </v-card>
    </div>
</template>
