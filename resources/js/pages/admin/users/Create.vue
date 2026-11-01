<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import UserForm from '@/components/admin/UserForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    roles: { type: Array, required: true },
    defaultRoles: { type: Array, default: () => [] },
});

const { link } = useInertiaLink();

const form = useForm({
    name: '',
    email: '',
    roles: props.defaultRoles,
    is_active: true,
    send_reset_link: true,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.transform((data) => (data.send_reset_link ? { ...data, password: null, password_confirmation: null } : data)).post(
        route('admin.users.store'),
    );
}
</script>

<template>
    <PageHeader title="Nuovo utente" subtitle="Crea un account e assegna i ruoli.">
        <template #actions>
            <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('admin.users.index'))">Utenti</v-btn>
        </template>
    </PageHeader>

    <v-card>
        <v-form @submit.prevent="submit">
            <v-card-text>
                <UserForm v-model:form="form" :roles="roles" is-new />
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn variant="text" v-bind="link(route('admin.users.index'))">Annulla</v-btn>
                <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Crea utente</v-btn>
            </v-card-actions>
        </v-form>
    </v-card>
</template>
