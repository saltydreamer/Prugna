<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import RoleForm from '@/components/admin/RoleForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

defineProps({
    permissionGroups: { type: Array, required: true },
});

const { link } = useInertiaLink();
const form = useForm({ name: '', permissions: [] });

function submit() {
    form.post(route('admin.roles.store'));
}
</script>

<template>
    <PageHeader title="Nuovo ruolo" subtitle="Definisci un ruolo e i permessi che concede.">
        <template #actions>
            <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('admin.roles.index'))">Ruoli</v-btn>
        </template>
    </PageHeader>

    <v-card>
        <v-form @submit.prevent="submit">
            <v-card-text>
                <RoleForm v-model:form="form" :permission-groups="permissionGroups" />
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn variant="text" v-bind="link(route('admin.roles.index'))">Annulla</v-btn>
                <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Crea ruolo</v-btn>
            </v-card-actions>
        </v-form>
    </v-card>
</template>
