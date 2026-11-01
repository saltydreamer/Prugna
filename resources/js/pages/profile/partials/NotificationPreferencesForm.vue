<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const props = defineProps({
    user: { type: Object, required: true },
});

const form = useForm({
    email_notifications: props.user.email_notifications,
});

function submit() {
    form.put(route('profile.preferences.update'), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <v-card title="Preferenze di notifica" subtitle="Le notifiche sono sempre disponibili nel centro notifiche dell'applicazione.">
        <v-form @submit.prevent="submit">
            <v-card-text>
                <v-switch
                    v-model="form.email_notifications"
                    label="Ricevi le notifiche anche via email"
                    :messages="
                        form.email_notifications
                            ? 'Riceverai una copia via email degli avvisi importanti.'
                            : 'Riceverai le notifiche solo nell\'applicazione.'
                    "
                    :error-messages="form.errors.email_notifications"
                />
            </v-card-text>
            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
            </v-card-actions>
        </v-form>
    </v-card>
</template>
