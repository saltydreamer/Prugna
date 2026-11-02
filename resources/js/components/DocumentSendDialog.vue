<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { http } from '@/utils/http';

const open = defineModel({ type: Boolean, default: false });

/**
 * Sends a document by email with its PDF: defaults from `composeUrl` ({ to, suggestions, subject, body, can_send }).
 */
const props = defineProps({
    title: { type: String, required: true },
    composeUrl: { type: String, required: true },
    sendUrl: { type: String, required: true },
    note: { type: String, default: 'Le risposte arrivano al tuo indirizzo.' },
});

const { link } = useInertiaLink();
const loading = ref(false);
const canSend = ref(true);
const suggestions = ref([]);

const form = useForm({ to: [], cc: [], subject: '', body: '', attach_pdf: true });

// Prefill from the template every time the dialog opens.
watch(open, async (value) => {
    if (!value) {
        return;
    }

    loading.value = true;
    form.clearErrors();

    try {
        const data = await http.get(props.composeUrl);
        Object.assign(form, { to: data.to, cc: [], subject: data.subject, body: data.body, attach_pdf: true });
        suggestions.value = data.suggestions;
        canSend.value = data.can_send;
    } finally {
        loading.value = false;
    }
});

function send() {
    form.post(props.sendUrl, { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <v-dialog v-model="open" max-width="720" scrollable>
        <v-card :title="title" :loading="loading">
            <v-divider />
            <v-card-text>
                <v-alert v-if="!canSend" type="warning" variant="tonal" class="mb-4">
                    Il server di posta non è configurato: le email non partirebbero.
                    <template #append>
                        <v-btn variant="text" v-bind="link(route('settings.index', { tab: 'mail' }))">Configura</v-btn>
                    </template>
                </v-alert>

                <v-combobox
                    v-model="form.to"
                    :items="suggestions"
                    label="A"
                    multiple
                    chips
                    closable-chips
                    hint="Scrivi un indirizzo e premi Invio per aggiungerlo"
                    persistent-hint
                    :error-messages="form.errors.to || form.errors['to.0']"
                    class="mb-2"
                />
                <v-combobox
                    v-model="form.cc"
                    :items="suggestions"
                    label="Cc"
                    multiple
                    chips
                    closable-chips
                    :error-messages="form.errors['cc.0']"
                    class="mb-2"
                />
                <v-text-field v-model="form.subject" label="Oggetto" :error-messages="form.errors.subject" class="mb-2" />
                <v-textarea v-model="form.body" label="Messaggio" rows="10" auto-grow :error-messages="form.errors.body" />
                <v-checkbox v-model="form.attach_pdf" label="Allega il PDF" hide-details />
                <div class="text-caption text-medium-emphasis mt-2">
                    {{ note }}
                </div>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-send-outline"
                    :loading="form.processing"
                    :disabled="loading || !canSend"
                    @click="send"
                >
                    Invia
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
