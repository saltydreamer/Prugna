<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { usePermissions } from '@/composables/usePermissions';

/**
 * "Carica documento": a photo, PDF or e-invoice XML is read (XML directly, the rest with the AI)
 * and the matching form opens prefilled, to be checked and confirmed.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    /** Preselected document kind: 'expense' | 'customer' | 'supplier'. */
    kind: { type: String, default: null },
});

const { can } = usePermissions();

const kinds = computed(() =>
    [
        {
            value: 'expense',
            icon: 'mdi-receipt-text-outline',
            title: 'Spesa o fattura fornitore',
            subtitle: 'Ricevuta, scontrino, fattura PDF o XML',
            allowed: can('expenses.manage'),
        },
        {
            value: 'supplier',
            icon: 'mdi-truck-outline',
            title: 'Nuovo fornitore',
            subtitle: 'Biglietto da visita, carta intestata, visura',
            allowed: can('suppliers.create') && can('ai.use'),
        },
        {
            value: 'customer',
            icon: 'mdi-domain',
            title: 'Nuovo cliente',
            subtitle: 'Biglietto da visita, carta intestata, visura',
            allowed: can('organizations.create') && can('ai.use'),
        },
    ].filter((kind) => kind.allowed),
);

const form = useForm({ kind: props.kind, file: null });
const fileInput = ref(null);
const usesAi = computed(() => can('ai.use'));
const accept = computed(() => (usesAi.value ? 'image/*,application/pdf,.xml,.p7m' : '.xml,.p7m'));

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
        form.kind = props.kind ?? (kinds.value.length === 1 ? kinds.value[0].value : null);
    }
});

function submit() {
    form.post(route('documents.read'), {
        forceFormData: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <v-dialog v-model="open" max-width="560" :persistent="form.processing">
        <v-card title="Carica documento" subtitle="I dati letti compilano il modulo: li controlli tu prima di salvare.">
            <v-card-text>
                <v-list v-if="!kind" density="comfortable" class="mb-4" border rounded="lg">
                    <v-list-item
                        v-for="item in kinds"
                        :key="item.value"
                        :prepend-icon="item.icon"
                        :title="item.title"
                        :subtitle="item.subtitle"
                        :active="form.kind === item.value"
                        color="primary"
                        @click="form.kind = item.value"
                    />
                </v-list>

                <v-file-input
                    ref="fileInput"
                    v-model="form.file"
                    :accept="accept"
                    label="Foto, PDF o fattura elettronica XML"
                    prepend-icon=""
                    prepend-inner-icon="mdi-paperclip"
                    show-size
                    :disabled="form.processing"
                    :error-messages="form.errors.file || form.errors.kind"
                    hint="Da telefono puoi scattare direttamente una foto. Massimo 20 MB."
                    persistent-hint
                />

                <v-alert v-if="!usesAi" type="info" variant="tonal" density="compact" class="mt-4">
                    Puoi importare le fatture elettroniche XML. Per leggere foto e PDF con l'AI serve il permesso dedicato.
                </v-alert>

                <div v-if="form.processing" class="d-flex align-center ga-3 mt-4">
                    <v-progress-circular indeterminate color="primary" size="24" width="3" />
                    <span class="text-body-2">Sto leggendo il documento… può richiedere fino a un minuto.</span>
                </div>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" :disabled="form.processing" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-text-recognition"
                    :loading="form.processing"
                    :disabled="!form.file || !form.kind"
                    @click="submit"
                >
                    Leggi documento
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
