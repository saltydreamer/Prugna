<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { usePermissions } from '@/composables/usePermissions';
import { http, upload } from '@/utils/http';

/**
 * «Carica più documenti»: the files are uploaded one by one, then read and recorded as expenses
 * in the background (new suppliers created, unreadable ones on the generic supplier).
 */
const open = defineModel({ type: Boolean, default: false });

/** ExpenseImportController::MAX_FILES */
const MAX_FILES = 50;
const MAX_SIZE = 20 * 1024 * 1024;

const { can } = usePermissions();
const usesAi = computed(() => can('ai.use'));
const accept = computed(() => (usesAi.value ? 'image/*,application/pdf,.xml,.p7m' : '.xml,.p7m'));

/** { file, name, size, status: waiting | uploading | done | error, error } */
const files = ref([]);
const importId = ref(null);
const busy = ref(false);
const error = ref(null);
const dragging = ref(false);
const picker = ref(null);

const waiting = computed(() => files.value.filter((entry) => entry.status === 'waiting').length);
const uploaded = computed(() => files.value.filter((entry) => entry.status === 'done').length);
const failed = computed(() => files.value.filter((entry) => entry.status === 'error').length);

watch(open, (value) => {
    if (value && !busy.value) {
        files.value = [];
        importId.value = null;
        error.value = null;
    }
});

function add(list) {
    error.value = null;

    for (const file of Array.from(list ?? [])) {
        if (files.value.length >= MAX_FILES) {
            error.value = `Al massimo ${MAX_FILES} documenti per volta.`;
            break;
        }

        files.value.push({
            file,
            name: file.name,
            size: file.size,
            status: file.size > MAX_SIZE ? 'error' : 'waiting',
            error: file.size > MAX_SIZE ? 'Più di 20 MB: non verrà caricato.' : null,
        });
    }
}

function onPick(event) {
    add(event.target.files);
    event.target.value = '';
}

function onDrop(event) {
    dragging.value = false;
    add(event.dataTransfer?.files);
}

function remove(index) {
    files.value.splice(index, 1);
}

function size(bytes) {
    return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`;
}

async function submit() {
    busy.value = true;
    error.value = null;

    try {
        importId.value ??= (await http.post(route('purchases.expenses.imports.store'))).id;

        for (const entry of files.value) {
            if (entry.status !== 'waiting') {
                continue;
            }

            entry.status = 'uploading';
            const data = new FormData();
            data.append('file', entry.file);

            try {
                await upload(route('purchases.expenses.imports.upload', importId.value), data);
                entry.status = 'done';
            } catch (exception) {
                entry.status = 'error';
                entry.error = exception.errors?.file?.[0] ?? exception.message;
            }
        }
    } catch (exception) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }

    // With errors the user sees them and starts the reading of the others.
    if (uploaded.value && !failed.value) {
        start();
    } else if (!uploaded.value && !error.value) {
        error.value = 'Nessun documento è stato caricato: controlla gli errori, chiudi e riprova.';
    }
}

function start() {
    router.post(route('purchases.expenses.imports.start', importId.value), {}, { onSuccess: () => (open.value = false) });
}
</script>

<template>
    <v-dialog v-model="open" max-width="640" :persistent="busy" scrollable>
        <v-card title="Carica più documenti" prepend-icon="mdi-file-multiple-outline">
            <v-card-text>
                <p class="text-body-2 mb-4">
                    Ogni documento diventa una spesa registrata. I fornitori nuovi vengono creati dal documento; quando il fornitore non è leggibile
                    la spesa va sul «Fornitore generico». I documenti già registrati vengono saltati.
                </p>

                <v-sheet
                    v-if="!importId"
                    border
                    rounded="lg"
                    class="drop-zone d-flex flex-column align-center justify-center pa-6 text-center"
                    :class="{ 'drop-zone--active': dragging }"
                    role="button"
                    tabindex="0"
                    @click="picker.click()"
                    @keydown.enter="picker.click()"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <v-icon icon="mdi-tray-arrow-up" size="32" color="primary" />
                    <div class="text-body-1 mt-2">Trascina qui i documenti o fai clic per sceglierli</div>
                    <div class="text-caption text-medium-emphasis">
                        {{ usesAi ? 'Foto, PDF e fatture elettroniche XML' : 'Fatture elettroniche XML' }} · al massimo {{ MAX_FILES }}, 20 MB l'uno
                    </div>
                    <input ref="picker" type="file" multiple :accept="accept" class="d-none" @change="onPick" />
                </v-sheet>

                <v-alert v-if="!usesAi" type="info" variant="tonal" density="compact" class="mt-4">
                    Puoi caricare le fatture elettroniche XML. Per leggere foto e PDF con l'AI serve il permesso dedicato.
                </v-alert>

                <v-list v-if="files.length" density="compact" class="mt-4 border rounded">
                    <v-list-item v-for="(entry, index) in files" :key="`${entry.name}-${index}`" :title="entry.name">
                        <template #prepend>
                            <v-progress-circular v-if="entry.status === 'uploading'" indeterminate size="20" width="2" color="primary" class="mr-4" />
                            <v-icon v-else-if="entry.status === 'done'" icon="mdi-check-circle" color="success" />
                            <v-icon v-else-if="entry.status === 'error'" icon="mdi-alert-circle" color="error" />
                            <v-icon v-else icon="mdi-file-document-outline" />
                        </template>
                        <v-list-item-subtitle :class="{ 'text-error': entry.error }">{{ entry.error ?? size(entry.size) }}</v-list-item-subtitle>
                        <template v-if="entry.status === 'waiting' && !busy && !importId" #append>
                            <v-btn icon="mdi-close" size="small" variant="text" title="Togli" @click="remove(index)" />
                        </template>
                    </v-list-item>
                </v-list>

                <v-alert v-if="importId && !busy && failed && uploaded" type="warning" variant="tonal" class="mt-4">
                    {{ failed === 1 ? 'Un documento non è stato caricato' : `${failed} documenti non sono stati caricati` }}: puoi leggere i
                    {{ uploaded }} caricati e riprovare gli altri dopo.
                </v-alert>
                <v-alert v-if="error" type="error" variant="tonal" class="mt-4">{{ error }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" :disabled="busy" @click="open = false">Annulla</v-btn>
                <v-btn v-if="importId && !busy && uploaded" color="primary" variant="flat" prepend-icon="mdi-text-recognition" @click="start">
                    Leggi i {{ uploaded }} documenti caricati
                </v-btn>
                <v-btn
                    v-else
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-upload"
                    :loading="busy"
                    :disabled="!waiting || !!importId"
                    @click="submit"
                >
                    Carica e registra{{ waiting ? ` (${waiting})` : '' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.drop-zone {
    border-style: dashed !important;
    cursor: pointer;
    min-height: 140px;
}

.drop-zone--active {
    background: rgba(var(--v-theme-primary), 0.06);
}
</style>
