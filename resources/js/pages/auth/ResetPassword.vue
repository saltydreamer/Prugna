<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    email: { type: String, default: '' },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Reimposta password" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <v-card-title class="text-h5 font-weight-bold">Scegli una nuova password</v-card-title>
        </v-card-item>

        <v-card-text>
            <v-form @submit.prevent="submit">
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
                    label="Nuova password"
                    type="password"
                    autocomplete="new-password"
                    :error-messages="form.errors.password"
                    autofocus
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
                <v-btn type="submit" color="primary" size="large" block :loading="form.processing">Reimposta password</v-btn>
            </v-form>
        </v-card-text>
    </v-card>
</template>
