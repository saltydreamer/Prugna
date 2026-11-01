<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    user: { type: Object, required: true },
});

const form = useForm({
    name: props.user.name,
    email: props.user.email,
});

const unverified = computed(() => !props.user.email_verified_at);

function submit() {
    form.put(route('user-profile-information.update'), {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

function resendVerification() {
    router.post(route('verification.send'), {}, { preserveScroll: true });
}
</script>

<template>
    <v-card title="Informazioni personali" subtitle="Aggiorna il nome e l'indirizzo email del tuo account.">
        <v-form @submit.prevent="submit">
            <v-card-text>
                <v-row>
                    <v-col cols="12" md="6">
                        <v-text-field v-model="form.name" label="Nome e cognome" autocomplete="name" :error-messages="form.errors.name" />
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-text-field v-model="form.email" label="Email" type="email" autocomplete="username" :error-messages="form.errors.email" />
                    </v-col>
                </v-row>

                <v-alert v-if="unverified" type="warning" variant="tonal" density="compact">
                    Il tuo indirizzo email non è verificato.
                    <a href="#" class="font-weight-medium" @click.prevent="resendVerification">Invia di nuovo il link di verifica.</a>
                </v-alert>
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
            </v-card-actions>
        </v-form>
    </v-card>
</template>
