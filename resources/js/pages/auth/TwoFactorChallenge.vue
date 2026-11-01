<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const recovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

function toggle() {
    recovery.value = !recovery.value;
    form.clearErrors();
    form.reset();
}

function submit() {
    form.transform((data) => (recovery.value ? { recovery_code: data.recovery_code } : { code: data.code })).post(route('two-factor.login.store'));
}
</script>

<template>
    <Head title="Verifica in due passaggi" />

    <v-card class="pa-2 pa-sm-4">
        <v-card-item>
            <template #prepend>
                <v-icon icon="mdi-shield-lock-outline" color="primary" size="40" />
            </template>
            <v-card-title class="text-h5 font-weight-bold">Verifica in due passaggi</v-card-title>
            <v-card-subtitle class="text-wrap">
                {{ recovery ? 'Inserisci uno dei tuoi codici di recupero.' : 'Inserisci il codice generato dalla tua app di autenticazione.' }}
            </v-card-subtitle>
        </v-card-item>

        <v-card-text>
            <v-form @submit.prevent="submit">
                <template v-if="!recovery">
                    <v-otp-input v-model="form.code" length="6" type="number" autofocus @finish="submit" />
                    <div v-if="form.errors.code" class="text-error text-body-2 text-center mb-2">{{ form.errors.code }}</div>
                </template>
                <v-text-field
                    v-else
                    v-model="form.recovery_code"
                    label="Codice di recupero"
                    autocomplete="one-time-code"
                    :error-messages="form.errors.recovery_code"
                    autofocus
                />

                <v-btn type="submit" color="primary" size="large" block class="mt-4" :loading="form.processing">Verifica</v-btn>
                <v-btn variant="text" block class="mt-2" @click="toggle">
                    {{ recovery ? "Usa l'app di autenticazione" : 'Usa un codice di recupero' }}
                </v-btn>
            </v-form>
        </v-card-text>
    </v-card>
</template>
