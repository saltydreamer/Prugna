<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useInertiaLink } from '@/composables/useInertiaLink';

defineProps({
    status: { type: String, default: null },
});

const { link } = useInertiaLink();
const form = useForm({ email: '' });

function submit() {
    form.post(route('password.email'));
}
</script>

<template>
    <Head title="Password dimenticata" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <v-card-title class="text-h5 font-weight-bold">Password dimenticata?</v-card-title>
            <v-card-subtitle class="text-wrap">Inserisci la tua email: ti invieremo un link per sceglierne una nuova.</v-card-subtitle>
        </v-card-item>

        <v-card-text>
            <v-alert v-if="status" type="success" variant="tonal" density="compact" class="mb-4">{{ status }}</v-alert>

            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    :error-messages="form.errors.email"
                    autofocus
                    class="mb-4"
                />
                <v-btn type="submit" color="primary" size="large" block :loading="form.processing">Invia il link</v-btn>
            </v-form>
        </v-card-text>

        <v-card-text class="text-center text-body-2 pt-0">
            <a v-bind="link(route('login'))" class="text-primary font-weight-medium">Torna al login</a>
        </v-card-text>
    </v-card>
</template>
