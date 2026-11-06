<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDate } from '@/utils/format';
import { http } from '@/utils/http';

/**
 * «Aggiorna dal Registro Imprese»: reads the company on Openapi ("Company" service) and lets the user
 * choose which differing fields to update.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, required: true },
});

const loading = ref(false);
const saving = ref(false);
const error = ref(null);
const company = ref(null);
const changes = ref([]);
const chosen = ref([]);

const details = computed(() =>
    company.value
        ? [
              company.value.legal_form,
              company.value.rea && `REA ${company.value.rea}`,
              company.value.ateco && `ATECO ${company.value.ateco}`,
              company.value.started_on && `attiva dal ${formatDate(company.value.started_on)}`,
          ].filter(Boolean)
        : [],
);

watch(open, async (value) => {
    if (!value) {
        return;
    }

    loading.value = true;
    error.value = null;
    company.value = null;
    changes.value = [];
    chosen.value = [];

    try {
        const data = await http.get(route('registry.organizations.company-data.show', props.organizationId));
        company.value = data.company;
        changes.value = data.changes;
        chosen.value = data.changes.map((change) => change.field);
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
});

function save() {
    router.put(
        route('registry.organizations.company-data.update', props.organizationId),
        { fields: chosen.value },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <v-dialog v-model="open" max-width="760" scrollable>
        <v-card title="Aggiorna dal Registro Imprese" prepend-icon="mdi-domain">
            <v-card-text>
                <div v-if="loading" class="py-8 text-center">
                    <v-progress-circular indeterminate color="primary" />
                    <div class="text-body-2 text-medium-emphasis mt-3">Ricerca dell'azienda su Openapi…</div>
                </div>

                <v-alert v-else-if="error" type="error" variant="tonal">{{ error }}</v-alert>

                <template v-else-if="company">
                    <v-alert v-if="company.ceased" type="error" variant="tonal" class="mb-4">
                        Nel Registro Imprese l'azienda risulta cessata{{ company.ceased_on ? ` dal ${formatDate(company.ceased_on)}` : '' }}.
                    </v-alert>
                    <v-alert v-else-if="company.status && company.status !== 'ATTIVA'" type="warning" variant="tonal" class="mb-4">
                        Stato nel Registro Imprese: {{ company.status_label }}.
                    </v-alert>

                    <div class="font-weight-medium">{{ company.name }}</div>
                    <div class="text-body-2 text-medium-emphasis mb-4">
                        {{ details.join(' · ') }}
                        <template v-if="company.updated_on">· dati aggiornati al {{ formatDate(company.updated_on) }}</template>
                    </div>

                    <v-alert v-if="!changes.length" type="success" variant="tonal">
                        Ragione sociale, dati fiscali, PEC e sede legale coincidono con il Registro Imprese.
                    </v-alert>

                    <template v-else>
                        <div class="text-body-2 mb-2">Scegli i dati da aggiornare:</div>
                        <v-list density="compact" class="border rounded">
                            <v-list-item v-for="change in changes" :key="change.field" class="py-2">
                                <template #prepend>
                                    <v-checkbox-btn v-model="chosen" :value="change.field" />
                                </template>
                                <v-list-item-title class="font-weight-medium">{{ change.label }}</v-list-item-title>
                                <div class="text-body-2">
                                    <span class="text-medium-emphasis" :class="{ 'text-decoration-line-through': change.current }">
                                        {{ change.current ?? 'non indicato' }}
                                    </span>
                                    <v-icon icon="mdi-arrow-right" size="small" class="mx-1" />
                                    <span>{{ change.proposed }}</span>
                                </div>
                            </v-list-item>
                        </v-list>
                    </template>
                </template>

                <div class="text-caption text-medium-emphasis mt-4">Dati dal servizio Company di Openapi: ogni ricerca ha un costo.</div>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">{{ changes.length ? 'Annulla' : 'Chiudi' }}</v-btn>
                <v-btn v-if="changes.length" color="primary" variant="flat" :disabled="!chosen.length" :loading="saving" @click="save">
                    Aggiorna i dati scelti
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
