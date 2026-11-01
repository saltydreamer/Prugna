<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({});

function resend() {
    form.post(route('verification.send'));
}

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <Head title="Verifica email" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <template #prepend>
                <v-icon icon="mdi-email-check-outline" color="primary" size="40" />
            </template>
            <v-card-title class="text-h5 font-weight-bold">Verifica la tua email</v-card-title>
        </v-card-item>

        <v-card-text>
            <p class="mb-4">
                Grazie per esserti registrato! Prima di iniziare, conferma il tuo indirizzo cliccando sul link che ti abbiamo appena inviato. Se non
                l'hai ricevuto, possiamo inviartene un altro.
            </p>

            <v-alert v-if="status === 'verification-link-sent'" type="success" variant="tonal" density="compact" class="mb-4">
                Un nuovo link di verifica è stato inviato al tuo indirizzo email.
            </v-alert>

            <div class="d-flex flex-wrap ga-2 justify-space-between">
                <v-btn color="primary" :loading="form.processing" @click="resend">Invia di nuovo</v-btn>
                <v-btn variant="text" @click="logout">Esci</v-btn>
            </div>
        </v-card-text>
    </v-card>
</template>
