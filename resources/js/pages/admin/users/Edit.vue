<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import UserForm from '@/components/admin/UserForm.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDateTime } from '@/utils/format';

const props = defineProps({
    user: { type: Object, required: true },
    roles: { type: Array, required: true },
    isSelf: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    roles: props.user.roles,
    is_active: props.user.is_active,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put(route('admin.users.update', props.user.id), {
        onError: () => form.reset('password', 'password_confirmation'),
    });
}

async function sendResetLink() {
    if (
        await confirm({
            title: 'Inviare il link di reset?',
            message: `L'utente riceverà un'email all'indirizzo ${props.user.email}.`,
            confirmText: 'Invia',
        })
    ) {
        router.post(route('admin.users.password-reset', props.user.id), {}, { preserveScroll: true });
    }
}

async function destroy() {
    const confirmed = await confirm({
        title: `Eliminare ${props.user.name}?`,
        message: "L'account e tutti i suoi dati verranno eliminati definitivamente.",
        confirmText: 'Elimina',
        color: 'error',
        icon: 'mdi-delete-outline',
    });

    if (confirmed) {
        router.delete(route('admin.users.destroy', props.user.id));
    }
}
</script>

<template>
    <PageHeader :title="user.name" subtitle="Modifica i dati, i ruoli e lo stato dell'utente.">
        <template #actions>
            <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('admin.users.index'))">Utenti</v-btn>
        </template>
    </PageHeader>

    <v-row>
        <v-col cols="12" lg="8">
            <v-card>
                <v-form @submit.prevent="submit">
                    <v-card-text>
                        <UserForm v-model:form="form" :roles="roles" :is-self="isSelf" />
                    </v-card-text>
                    <v-card-actions class="px-4 pb-4">
                        <v-spacer />
                        <v-btn variant="text" v-bind="link(route('admin.users.index'))">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-col>

        <v-col cols="12" lg="4">
            <v-card class="mb-4">
                <v-card-item>
                    <template #prepend><UserAvatar :user="user" size="56" class="mr-2" /></template>
                    <v-card-title>{{ user.name }}</v-card-title>
                    <v-card-subtitle>{{ user.email }}</v-card-subtitle>
                </v-card-item>
                <v-list density="compact">
                    <v-list-item prepend-icon="mdi-calendar-outline" title="Registrato il" :subtitle="formatDateTime(user.created_at)" />
                    <v-list-item prepend-icon="mdi-login" title="Ultimo accesso" :subtitle="formatDateTime(user.last_login_at)" />
                    <v-list-item
                        prepend-icon="mdi-email-check-outline"
                        title="Email verificata"
                        :subtitle="user.email_verified_at ? formatDateTime(user.email_verified_at) : 'No'"
                    />
                    <v-list-item prepend-icon="mdi-shield-lock-outline" title="2FA" :subtitle="user.two_factor_enabled ? 'Attiva' : 'Non attiva'" />
                </v-list>
            </v-card>

            <v-card title="Azioni">
                <v-card-text class="d-flex flex-column ga-2">
                    <v-btn variant="tonal" prepend-icon="mdi-lock-reset" @click="sendResetLink">Invia link reset password</v-btn>
                    <v-btn v-if="user.can?.delete" variant="tonal" color="error" prepend-icon="mdi-delete-outline" @click="destroy">
                        Elimina utente
                    </v-btn>
                </v-card-text>
            </v-card>
        </v-col>
    </v-row>
</template>
