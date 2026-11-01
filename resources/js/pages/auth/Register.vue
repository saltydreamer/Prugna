<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useInertiaLink } from '@/composables/useInertiaLink';

const { link } = useInertiaLink();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('register.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Registrati" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <v-card-title class="text-h5 font-weight-bold">Crea un account</v-card-title>
            <v-card-subtitle>Bastano pochi secondi</v-card-subtitle>
        </v-card-item>

        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.name"
                    label="Nome e cognome"
                    autocomplete="name"
                    :error-messages="form.errors.name"
                    autofocus
                    class="mb-2"
                />
                <v-text-field
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    :error-messages="form.errors.email"
                    class="mb-2"
                />
                <v-text-field
                    v-model="form.password"
                    label="Password"
                    type="password"
                    autocomplete="new-password"
                    :error-messages="form.errors.password"
                    class="mb-2"
                />
                <v-text-field
                    v-model="form.password_confirmation"
                    label="Conferma password"
                    type="password"
                    autocomplete="new-password"
                    :error-messages="form.errors.password_confirmation"
                    class="mb-4"
                />

                <v-btn type="submit" color="primary" size="large" block :loading="form.processing">Registrati</v-btn>
            </v-form>
        </v-card-text>

        <v-card-text class="text-center text-body-2 pt-0">
            Hai già un account?
            <a v-bind="link(route('login'))" class="text-primary font-weight-medium">Accedi</a>
        </v-card-text>
    </v-card>
</template>
