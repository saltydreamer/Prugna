<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

defineProps({
    sessions: { type: Array, default: () => [] },
});

const dialog = ref(false);
const form = useForm({ password: '' });

function submit() {
    form.delete(route('profile.sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
        onError: () => form.reset(),
    });
}
</script>

<template>
    <v-card title="Sessioni attive" subtitle="Dispositivi su cui il tuo account è attualmente connesso.">
        <v-list v-if="sessions.length" lines="two">
            <v-list-item v-for="session in sessions" :key="session.id">
                <template #prepend>
                    <v-avatar color="primary" variant="tonal">
                        <v-icon :icon="session.device.desktop ? 'mdi-monitor' : 'mdi-cellphone'" />
                    </v-avatar>
                </template>
                <v-list-item-title>{{ session.device.platform }} · {{ session.device.browser }}</v-list-item-title>
                <v-list-item-subtitle>
                    {{ session.ip_address }} ·
                    <span v-if="session.is_current_device" class="text-success font-weight-medium">Questo dispositivo</span>
                    <span v-else>Ultima attività {{ session.last_active }}</span>
                </v-list-item-subtitle>
            </v-list-item>
        </v-list>
        <v-card-text v-else class="text-medium-emphasis">
            Elenco non disponibile: richiede
            <code>SESSION_DRIVER=database</code>
            .
        </v-card-text>

        <v-card-actions class="px-4 pb-4">
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-logout-variant" @click="dialog = true">Disconnetti le altre sessioni</v-btn>
        </v-card-actions>

        <v-dialog v-model="dialog" max-width="440">
            <v-card title="Disconnetti le altre sessioni" subtitle="Inserisci la password per confermare.">
                <v-form @submit.prevent="submit">
                    <v-card-text>
                        <v-text-field
                            v-model="form.password"
                            label="Password"
                            type="password"
                            autocomplete="current-password"
                            :error-messages="form.errors.password"
                            autofocus
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="dialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Disconnetti</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </v-card>
</template>
