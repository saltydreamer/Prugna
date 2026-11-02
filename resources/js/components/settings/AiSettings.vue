<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDateTime } from '@/utils/format';
import { http, upload } from '@/utils/http';

/**
 * Impostazioni → AI: OpenRouter key, model chosen from the live catalog, privacy, test on a sample document, usage.
 */
const props = defineProps({
    ai: { type: Object, required: true },
    privacyLevels: { type: Array, required: true },
    usage: { type: Object, default: null },
});

const form = useForm({
    enabled: props.ai.enabled,
    api_key: '',
    model: props.ai.model,
    privacy: props.ai.privacy ?? 'no_training',
});

function save() {
    form.put(route('settings.ai.update'), { preserveScroll: true, preserveState: true, onSuccess: () => form.reset('api_key') });
}

// --- Model catalog ---------------------------------------------------------------------
const models = ref([]);
const loadingModels = ref(false);
const modelsError = ref('');

async function loadModels(refresh = false) {
    if (!props.ai.has_api_key && !form.api_key) {
        modelsError.value = 'Inserisci prima la chiave API di OpenRouter.';

        return;
    }

    loadingModels.value = true;
    modelsError.value = '';

    try {
        const query = new URLSearchParams({ refresh: refresh ? '1' : '0' });

        if (form.api_key) {
            query.set('api_key', form.api_key);
        }

        const { data } = await http.get(`${route('settings.ai.models')}?${query}`);
        models.value = data;
    } catch (error) {
        modelsError.value = error.message;
    } finally {
        loadingModels.value = false;
    }
}

const modelItems = computed(() =>
    models.value.map((model) => ({
        value: model.id,
        title: model.name,
        subtitle: `${model.id} · $${model.prompt_price}/M input · $${model.completion_price}/M output${model.reads_pdf ? ' · legge i PDF' : ''}`,
    })),
);

const selectedModel = computed(() => models.value.find((model) => model.id === form.model));

// --- Test --------------------------------------------------------------------------------
const testFile = ref(null);
const testKind = ref('expense');
const testing = ref(false);
const testResult = ref(null);
const testError = ref('');

async function runTest() {
    testing.value = true;
    testError.value = '';
    testResult.value = null;

    const data = new FormData();
    data.append('file', testFile.value);
    data.append('kind', testKind.value);

    if (form.model) {
        data.append('model', form.model);
    }

    try {
        testResult.value = await upload(route('settings.ai.test'), data);
    } catch (error) {
        testError.value = error.message || 'Lettura non riuscita.';
        testResult.value = null;
    } finally {
        testing.value = false;
        router.reload({ only: ['aiUsage'] });
    }
}

onMounted(() => {
    if (!props.usage) {
        router.reload({ only: ['aiUsage'] });
    }

    if (props.ai.has_api_key) {
        loadModels();
    }
});
</script>

<template>
    <div>
        <v-card class="mb-6" title="Lettura documenti con l'AI" subtitle="Tramite OpenRouter: una chiave, molti modelli.">
            <v-form @submit.prevent="save">
                <v-card-text>
                    <v-switch v-model="form.enabled" label="Attiva la lettura dei documenti" color="primary" hide-details class="mb-4" />

                    <v-text-field
                        v-model="form.api_key"
                        label="Chiave API OpenRouter"
                        type="password"
                        autocomplete="off"
                        :placeholder="ai.has_api_key ? '•••••••••• (salvata: lascia vuoto per non cambiarla)' : 'sk-or-…'"
                        persistent-placeholder
                        hint="La crei su openrouter.ai → Keys. Viene salvata cifrata e non è mai mostrata."
                        persistent-hint
                        :error-messages="form.errors.api_key"
                        class="mb-4"
                    />

                    <div class="d-flex ga-2 align-start">
                        <v-combobox
                            v-model="form.model"
                            :items="modelItems"
                            item-props
                            :return-object="false"
                            label="Modello"
                            :loading="loadingModels"
                            :error-messages="form.errors.model || modelsError"
                            hint="Solo modelli che leggono immagini e restituiscono dati strutturati, dal più economico."
                            persistent-hint
                            class="flex-grow-1"
                        />
                        <v-btn
                            icon="mdi-refresh"
                            variant="tonal"
                            title="Aggiorna l'elenco dei modelli"
                            :loading="loadingModels"
                            @click="loadModels(true)"
                        />
                    </div>
                    <div v-if="selectedModel" class="text-caption text-medium-emphasis mt-2">
                        ${{ selectedModel.prompt_price }} per milione di token in ingresso, ${{ selectedModel.completion_price }} in uscita.
                        {{
                            selectedModel.reads_pdf
                                ? 'Legge i PDF direttamente.'
                                : 'I PDF vengono convertiti da OpenRouter (costo aggiuntivo per pagina).'
                        }}
                    </div>

                    <v-select v-model="form.privacy" :items="privacyLevels" label="Privacy" :error-messages="form.errors.privacy" class="mt-4" />
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-spacer />
                    <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
                </v-card-actions>
            </v-form>
        </v-card>

        <v-card class="mb-6" title="Prova su un documento" subtitle="Confronta modelli diversi sui tuoi documenti reali: non viene registrato nulla.">
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="7">
                        <v-file-input
                            v-model="testFile"
                            label="Foto o PDF di esempio"
                            accept="image/*,application/pdf"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip"
                            show-size
                        />
                    </v-col>
                    <v-col cols="12" md="5">
                        <v-select
                            v-model="testKind"
                            :items="[
                                { value: 'expense', title: 'Spesa / fattura fornitore' },
                                { value: 'organization', title: 'Anagrafica (biglietto da visita…)' },
                            ]"
                            label="Tipo"
                        />
                    </v-col>
                </v-row>
                <v-btn
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-flask-outline"
                    :loading="testing"
                    :disabled="!testFile || (!ai.has_api_key && !form.api_key) || !form.model || form.isDirty"
                    @click="runTest"
                >
                    Prova con {{ form.model || 'il modello scelto' }}
                </v-btn>
                <div v-if="form.isDirty" class="text-caption text-medium-emphasis mt-1">Salva le impostazioni prima della prova.</div>

                <v-alert v-if="testError" type="error" variant="tonal" class="mt-4">{{ testError }}</v-alert>
                <div v-if="testResult" class="mt-4">
                    <div class="d-flex flex-wrap ga-2 mb-2">
                        <v-chip size="small" prepend-icon="mdi-robot-outline">{{ testResult.model }}</v-chip>
                        <v-chip size="small" prepend-icon="mdi-cash">
                            {{ testResult.cost !== null ? `$ ${testResult.cost.toFixed(4)}` : 'costo n.d.' }}
                        </v-chip>
                        <v-chip size="small" prepend-icon="mdi-timer-outline">{{ (testResult.duration_ms / 1000).toFixed(1) }} s</v-chip>
                        <v-chip size="small" prepend-icon="mdi-counter">
                            {{ testResult.tokens.prompt }} + {{ testResult.tokens.completion }} token
                        </v-chip>
                    </div>
                    <pre class="text-caption pa-3 bg-surface-light rounded" style="max-height: 420px; overflow: auto">{{
                        JSON.stringify(testResult.result, null, 2)
                    }}</pre>
                </div>
            </v-card-text>
        </v-card>

        <v-card title="Utilizzo">
            <v-card-text v-if="usage">
                <div class="text-body-1 mb-4">
                    Questo mese:
                    <strong>{{ usage.month_count }}</strong>
                    letture con l'AI ·
                    <strong>{{ `$ ${usage.month_cost.toFixed(4)}` }}</strong>
                    <span class="text-caption text-medium-emphasis ml-1">(costi in dollari, come addebitati da OpenRouter)</span>
                </div>
                <v-table v-if="usage.latest.length" density="compact">
                    <thead>
                        <tr>
                            <th>Quando</th>
                            <th>File</th>
                            <th>Modello</th>
                            <th class="text-right">Costo</th>
                            <th class="text-right">Tempo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in usage.latest" :key="item.id">
                            <td class="text-no-wrap">
                                {{ formatDateTime(item.created_at) }}
                                <div class="text-caption text-medium-emphasis">{{ item.user }}</div>
                            </td>
                            <td>
                                {{ item.file }}
                                <div v-if="item.status === 'failed'" class="text-caption text-error">{{ item.error }}</div>
                            </td>
                            <td class="text-caption">{{ item.source === 'xml' ? 'XML (senza AI)' : item.model }}</td>
                            <td class="text-right text-no-wrap">{{ item.cost !== null ? `$ ${item.cost.toFixed(4)}` : '—' }}</td>
                            <td class="text-right text-no-wrap">{{ item.duration_ms ? `${(item.duration_ms / 1000).toFixed(1)} s` : '—' }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
            <v-card-text v-else><v-skeleton-loader type="paragraph" /></v-card-text>
        </v-card>
    </div>
</template>
