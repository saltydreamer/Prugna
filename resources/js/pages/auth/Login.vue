<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useInertiaLink } from '@/composables/useInertiaLink';

defineProps({
    canResetPassword: { type: Boolean, default: true },
    canRegister: { type: Boolean, default: true },
    status: { type: String, default: null },
});

const { link } = useInertiaLink();
const showPassword = ref(false);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login.store'), {
        onFinish: () => form.reset('password'),
    });
}

/** Demonstration instance: the demo account, with fictitious data. */
const demo = computed(() => usePage().props.app?.demo ?? null);

function enterDemo() {
    form.email = demo.value.email;
    form.password = demo.value.password;
    submit();
}
</script>

<template>
    <Head title="Accedi" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <v-card-title class="text-h5 font-weight-bold">Bentornato</v-card-title>
            <v-card-subtitle>Accedi al tuo account per continuare</v-card-subtitle>
        </v-card-item>

        <v-card-text>
            <v-alert v-if="demo" type="info" variant="tonal" class="mb-4" title="Ambiente dimostrativo">
                <div class="text-body-2">
                    Dati di fantasia di un sistemista a partita IVA in regime forfettario. Accedi con
                    <strong>{{ demo.email }}</strong>
                    e password
                    <strong>{{ demo.password }}</strong>
                    .
                </div>
                <v-btn color="info" variant="flat" size="small" class="mt-3" prepend-icon="mdi-login" :loading="form.processing" @click="enterDemo">
                    Entra nella demo
                </v-btn>
            </v-alert>
            <v-alert v-if="status" type="success" variant="tonal" density="compact" class="mb-4">{{ status }}</v-alert>
            <v-alert v-if="form.errors.session" type="warning" variant="tonal" density="compact" class="mb-4">
                {{ form.errors.session }}
            </v-alert>

            <v-form @submit.prevent="submit">
                <v-text-field
                    v-model="form.email"
                    label="Email"
                    type="email"
                    autocomplete="username"
                    prepend-inner-icon="mdi-email-outline"
                    :error-messages="form.errors.email"
                    autofocus
                    class="mb-2"
                />
                <v-text-field
                    v-model="form.password"
                    label="Password"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="current-password"
                    prepend-inner-icon="mdi-lock-outline"
                    :append-inner-icon="showPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                    :error-messages="form.errors.password"
                    @click:append-inner="showPassword = !showPassword"
                />

                <div class="d-flex align-center justify-space-between flex-wrap mb-4">
                    <v-checkbox v-model="form.remember" label="Ricordami" hide-details />
                    <a v-if="canResetPassword" v-bind="link(route('password.request'))" class="text-body-2 text-primary">Password dimenticata?</a>
                </div>

                <v-btn type="submit" color="primary" size="large" block :loading="form.processing">Accedi</v-btn>
            </v-form>
        </v-card-text>

        <v-card-text v-if="canRegister" class="text-center text-body-2 pt-0">
            Non hai un account?
            <a v-bind="link(route('register'))" class="text-primary font-weight-medium">Registrati</a>
        </v-card-text>
    </v-card>
</template>
