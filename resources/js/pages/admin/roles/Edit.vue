<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import RoleForm from '@/components/admin/RoleForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    role: { type: Object, required: true },
    permissionGroups: { type: Array, required: true },
});

const { link } = useInertiaLink();
const form = useForm({ name: props.role.name, permissions: props.role.permissions });

function submit() {
    form.put(route('admin.roles.update', props.role.id));
}
</script>

<template>
    <PageHeader :title="`Ruolo: ${role.label}`" subtitle="Modifica i permessi concessi dal ruolo.">
        <template #actions>
            <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('admin.roles.index'))">Ruoli</v-btn>
        </template>
    </PageHeader>

    <v-card>
        <v-form @submit.prevent="submit">
            <v-card-text>
                <RoleForm v-model:form="form" :permission-groups="permissionGroups" :lock-name="role.is_system" />
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn variant="text" v-bind="link(route('admin.roles.index'))">Annulla</v-btn>
                <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
            </v-card-actions>
        </v-form>
    </v-card>
</template>
