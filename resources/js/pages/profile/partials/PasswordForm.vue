<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put(route('user-password.update'), {
        errorBag: 'updatePassword',
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <v-card title="Password" subtitle="Usa una password lunga e unica per proteggere il tuo account.">
        <v-form @submit.prevent="submit">
            <v-card-text>
                <v-row>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="form.current_password"
                            label="Password attuale"
                            type="password"
                            autocomplete="current-password"
                            :error-messages="form.errors.current_password"
                        />
                    </v-col>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="form.password"
                            label="Nuova password"
                            type="password"
                            autocomplete="new-password"
                            :error-messages="form.errors.password"
                        />
                    </v-col>
                    <v-col cols="12" md="4">
                        <v-text-field
                            v-model="form.password_confirmation"
                            label="Conferma nuova password"
                            type="password"
                            autocomplete="new-password"
                            :error-messages="form.errors.password_confirmation"
                        />
                    </v-col>
                </v-row>
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Aggiorna password</v-btn>
            </v-card-actions>
        </v-form>
    </v-card>
</template>
