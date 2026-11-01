<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const dialog = ref(false);
const form = useForm({ password: '' });

function submit() {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onError: () => form.reset(),
    });
}
</script>

<template>
    <v-card title="Elimina account" subtitle="Questa operazione è definitiva.">
        <v-card-text>
            Eliminando l'account verranno cancellati in modo permanente tutti i tuoi dati, comprese le notifiche e l'immagine del profilo.
        </v-card-text>
        <v-card-actions class="px-4 pb-4">
            <v-btn color="error" variant="flat" prepend-icon="mdi-delete-forever-outline" @click="dialog = true">Elimina il mio account</v-btn>
        </v-card-actions>

        <v-dialog v-model="dialog" max-width="460">
            <v-card prepend-icon="mdi-alert-outline" title="Eliminare definitivamente l'account?">
                <v-form @submit.prevent="submit">
                    <v-card-text>
                        <p class="mb-4">Inserisci la password per confermare. Non sarà possibile recuperare l'account.</p>
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
                        <v-btn type="submit" color="error" variant="flat" :loading="form.processing">Elimina account</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </v-card>
</template>
