<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const form = useForm({ password: '' });

function submit() {
    form.post(route('password.confirm.store'), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Conferma password" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <v-card-title class="text-h5 font-weight-bold">Conferma la password</v-card-title>
            <v-card-subtitle class="text-wrap">Stai accedendo a un'area protetta: conferma la tua password per continuare.</v-card-subtitle>
        </v-card-item>

        <v-card-text>
            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.password"
                    label="Password"
                    type="password"
                    autocomplete="current-password"
                    :error-messages="form.errors.password"
                    autofocus
                    class="mb-4"
                />
                <v-btn type="submit" color="primary" size="large" block :loading="form.processing">Conferma</v-btn>
            </v-form>
        </v-card-text>
    </v-card>
</template>
