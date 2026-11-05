<script setup>
import { computed, watch } from 'vue';
import { router, useForm, usePoll } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { formatDateTime } from '@/utils/format';

const props = defineProps({
    imports: { type: Array, required: true },
    maxUploadMb: { type: Number, required: true },
});

const { confirm } = useConfirm();

const form = useForm({ backup: null });

function upload() {
    form.post(route('settings.dolibarr.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
}

// Refresh the list while a simulation or import runs in the background.
const running = computed(() => props.imports.some((item) => item.running));
const { start, stop } = usePoll(3000, { only: ['imports'] }, { autoStart: false });
watch(running, (value) => (value ? start() : stop()), { immediate: true });

const statuses = {
    uploaded: { label: 'In coda', color: 'info' },
    simulating: { label: 'Simulazione in corso', color: 'info' },
    simulated: { label: 'Simulato', color: 'success' },
    importing: { label: 'Importazione in corso', color: 'info' },
    imported: { label: 'Importato', color: 'success' },
    failed: { label: 'Non riuscito', color: 'error' },
};

function status(item) {
    if (item.status === 'simulated' && item.report?.errors?.length) {
        return { label: 'Da correggere', color: 'warning' };
    }

    return statuses[item.status] ?? { label: item.status, color: 'grey' };
}

const columns = [
    { key: 'created', title: 'Nuovi' },
    { key: 'updated', title: 'Aggiornati' },
    { key: 'linked', title: 'Collegati' },
    { key: 'skipped', title: 'Saltati' },
];

function size(bytes) {
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

const options = { preserveScroll: true };

function simulate(item) {
    router.post(route('settings.dolibarr.simulate', item.id), {}, options);
}

async function runImport(item) {
    const confirmed = await confirm({
        title: 'Importare i dati in Prugna?',
        message:
            "Verranno salvati i dati della simulazione. Le schede già importate vengono aggiornate con i dati di Dolibarr, non duplicate. Dopo l'importazione il backup viene eliminato dal server.",
        confirmText: 'Importa',
    });

    if (confirmed) {
        router.post(route('settings.dolibarr.import', item.id), {}, options);
    }
}

async function remove(item) {
    if (
        await confirm({
            title: 'Eliminare questo backup?',
            message: 'Il file e il riepilogo vengono cancellati. I dati già importati restano.',
            confirmText: 'Elimina',
            color: 'error',
        })
    ) {
        router.delete(route('settings.dolibarr.destroy', item.id), options);
    }
}
</script>

<template>
    <div>
        <PageHeader title="Importa da Dolibarr" subtitle="Anagrafiche, catalogo e documenti dal backup del database di Dolibarr." />

        <v-row>
            <v-col cols="12" md="7">
                <v-card title="Come procedere" class="h-100">
                    <v-card-text>
                        <ol class="steps">
                            <li>
                                In Dolibarr apri
                                <strong>Home → Strumenti di amministrazione → Backup</strong>
                                , scegli il metodo
                                <strong>Mysqldump</strong>
                                (o quello PHP) e la compressione
                                <strong>Gzip</strong>
                                o nessuna, poi genera il backup e scaricalo.
                            </li>
                            <li>
                                Caricalo qui: parte una
                                <strong>simulazione</strong>
                                , che non salva nulla e mostra cosa verrà importato.
                            </li>
                            <li>
                                Controlla il riepilogo e premi
                                <strong>Importa</strong>
                                .
                            </li>
                        </ol>
                        <p class="text-medium-emphasis mt-3 mb-0">
                            Puoi ripetere l'importazione con un backup più recente finché usi ancora Dolibarr: le schede già importate vengono
                            aggiornate, non duplicate. Le fatture importate mantengono il loro numero e non possono essere rinviate allo SDI; la
                            numerazione di Prugna prosegue dall'ultima fattura dell'anno.
                        </p>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="5">
                <v-card title="Carica il backup" :subtitle="`File .sql, .sql.gz o .zip, fino a ${maxUploadMb} MB`" class="h-100">
                    <v-form @submit.prevent="upload">
                        <v-card-text>
                            <v-file-input
                                v-model="form.backup"
                                label="Backup del database di Dolibarr"
                                accept=".sql,.gz,.zip"
                                prepend-icon=""
                                prepend-inner-icon="mdi-database-arrow-up-outline"
                                :error-messages="form.errors.backup"
                                :disabled="running"
                                show-size
                            />
                            <v-progress-linear v-if="form.progress" :model-value="form.progress.percentage" color="primary" class="mt-2" />
                            <p class="text-caption text-medium-emphasis mt-2 mb-0">
                                Il file resta in un'area privata del server e viene eliminato dopo l'importazione.
                            </p>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4">
                            <v-spacer />
                            <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.backup || running">
                                Carica e simula
                            </v-btn>
                        </v-card-actions>
                    </v-form>
                </v-card>
            </v-col>
        </v-row>

        <div v-if="imports.length" class="d-flex flex-column ga-4 mt-6">
            <v-card v-for="item in imports" :key="item.id">
                <v-card-item>
                    <v-card-title class="text-wrap">{{ item.file_name }}</v-card-title>
                    <v-card-subtitle class="text-wrap">
                        {{ size(item.file_size) }} · caricato {{ formatDateTime(item.created_at) }}
                        <template v-if="item.user">da {{ item.user }}</template>
                        <template v-if="item.imported_at">· importato {{ formatDateTime(item.imported_at) }}</template>
                    </v-card-subtitle>
                    <template #append>
                        <v-chip :color="status(item).color" variant="tonal">{{ status(item).label }}</v-chip>
                    </template>
                </v-card-item>

                <v-progress-linear v-if="item.running" indeterminate color="primary" />

                <v-card-text v-if="item.error || item.report">
                    <v-alert v-if="item.error" type="error" variant="tonal" class="mb-4">{{ item.error }}</v-alert>

                    <template v-if="item.report">
                        <div class="text-body-2 text-medium-emphasis mb-3">
                            <template v-if="item.report.source.company">{{ item.report.source.company }} ·</template>
                            Dolibarr
                            {{ item.report.source.version ?? '' }}
                            <template v-if="item.report.source.simulation">· simulazione: nessun dato salvato</template>
                        </div>

                        <v-alert v-for="error in item.report.errors" :key="error.message" type="error" variant="tonal" class="mb-3">
                            <div class="font-weight-medium">{{ error.message }}</div>
                            <div v-if="error.examples.length" class="text-body-2 mt-1">{{ error.examples.join(', ') }}</div>
                        </v-alert>

                        <v-table density="compact" class="report-table mb-4">
                            <thead>
                                <tr>
                                    <th>Dati</th>
                                    <th v-for="column in columns" :key="column.key" class="text-end">{{ column.title }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="section in item.report.sections" :key="section.key">
                                    <td>{{ section.label }}</td>
                                    <td
                                        v-for="column in columns"
                                        :key="column.key"
                                        class="text-end"
                                        :class="{ 'text-disabled': !section[column.key] }"
                                    >
                                        {{ section[column.key] }}
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <div v-if="Object.keys(item.report.next_numbers ?? {}).length" class="text-body-2 mb-4">
                            Prossimo numero in Prugna:
                            <span v-for="(next, series) in item.report.next_numbers" :key="series" class="mr-3">
                                <strong>{{ series }}</strong>
                                n. {{ next }}
                            </span>
                        </div>

                        <v-list v-if="item.report.notes.length" density="compact" class="pa-0 mb-2">
                            <v-list-item
                                v-for="note in item.report.notes"
                                :key="note"
                                prepend-icon="mdi-information-outline"
                                :title="note"
                                class="px-0"
                            />
                        </v-list>

                        <v-expansion-panels v-if="item.report.warnings.length" variant="accordion" multiple>
                            <v-expansion-panel v-for="warning in item.report.warnings" :key="warning.message">
                                <v-expansion-panel-title>
                                    <v-icon icon="mdi-alert-outline" color="warning" size="small" class="mr-2" />
                                    <span class="flex-grow-1">{{ warning.message }}</span>
                                    <v-chip size="x-small" variant="tonal" class="ml-2">{{ warning.count }}</v-chip>
                                </v-expansion-panel-title>
                                <v-expansion-panel-text v-if="warning.examples.length">
                                    <span class="text-body-2">
                                        {{ warning.examples.join(', ') }}
                                        <template v-if="warning.count > warning.examples.length">…</template>
                                    </span>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>
                    </template>
                </v-card-text>

                <v-card-actions
                    v-if="!item.running && (item.can_import || item.can_simulate || item.status !== 'imported')"
                    class="px-4 pb-4 flex-wrap ga-2"
                >
                    <v-btn v-if="item.can_simulate" variant="text" prepend-icon="mdi-refresh" @click="simulate(item)">Ripeti simulazione</v-btn>
                    <v-btn variant="text" color="error" @click="remove(item)">Elimina</v-btn>
                    <v-spacer />
                    <v-btn v-if="item.can_import" color="primary" variant="flat" prepend-icon="mdi-database-import-outline" @click="runImport(item)">
                        Importa
                    </v-btn>
                </v-card-actions>
            </v-card>
        </div>
    </div>
</template>

<style scoped>
.steps {
    padding-left: 1.25rem;
}

.steps li + li {
    margin-top: 0.5rem;
}

.report-table td:first-child {
    width: 40%;
}
</style>
