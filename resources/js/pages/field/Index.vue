<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import FieldInterventionDialog from '@/components/field/FieldInterventionDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { useToast } from '@/composables/useToast';
import { formatElapsed, formatMinutes } from '@/utils/duration';
import { fieldQueue, loadQueue, newClientUuid, removeFromQueue, saveToQueue, syncQueue } from '@/utils/fieldQueue';

/**
 * Interventi sul campo: works without connection (the service worker keeps this page and its data).
 * Interventions are saved on the phone and sent to the server when the connection returns.
 */
const props = defineProps({
    customers: { type: Array, required: true },
    categories: { type: Array, required: true },
    modes: { type: Array, required: true },
    products: { type: Array, required: true },
    rounding: { type: Number, required: true },
    userId: { type: Number, required: true },
    recent: { type: Array, required: true },
    cachedAt: { type: String, required: true },
});

const page = usePage();
const { link } = useInertiaLink();
const { confirm } = useConfirm();
const toast = useToast();

const serverTimer = computed(() => page.props.runningIntervention);
const waiting = computed(() => fieldQueue.items.filter((item) => item.status !== 'synced'));
const synced = computed(() => fieldQueue.items.filter((item) => item.status === 'synced'));
const dateTime = (iso) => new Date(iso).toLocaleString('it-IT', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

const now = ref(Date.now());
let ticker;

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);
    loadQueue(props.userId).then(syncQueue);
});
onBeforeUnmount(() => clearInterval(ticker));

// --- Local timer (works offline) -----------------------------------------------------------
const TIMER_KEY = `field-timer-${props.userId}`;

function readTimer() {
    try {
        return JSON.parse(localStorage.getItem(TIMER_KEY) ?? 'null');
    } catch {
        return null;
    }
}

const timer = ref(readTimer());
const timerForm = reactive({ organization_id: null, category_id: props.categories[0]?.value ?? null, mode: 'remote' });

function startTimer() {
    if (!timerForm.organization_id || !timerForm.category_id) {
        toast.error('Scegli cliente e categoria.');

        return;
    }

    timer.value = { ...timerForm, started_at: new Date().toISOString() };
    localStorage.setItem(TIMER_KEY, JSON.stringify(timer.value));
}

function clearTimer() {
    timer.value = null;
    localStorage.removeItem(TIMER_KEY);
}

async function cancelTimer() {
    if (await confirm({ title: 'Annullare il timer?', message: 'Il tempo misurato va perso.', confirmText: 'Annulla timer', color: 'error' })) {
        clearTimer();
    }
}

const timerCustomer = computed(() => props.customers.find((item) => item.value === timer.value?.organization_id)?.title);

// --- Form ----------------------------------------------------------------------------------
const dialog = ref(false);
const initial = ref(null);
const editing = ref(null);

function pad(value) {
    return String(value).padStart(2, '0');
}

function localInput(iso) {
    const date = new Date(iso);

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function newIntervention() {
    editing.value = null;
    initial.value = null;
    dialog.value = true;
}

function stopTimer() {
    const minutes = Math.max(1, Math.round((Date.now() - new Date(timer.value.started_at).getTime()) / 60000));
    editing.value = null;
    initial.value = {
        organization_id: timer.value.organization_id,
        location_id: props.customers.find((item) => item.value === timer.value.organization_id)?.locations?.[0]?.value ?? null,
        category_id: timer.value.category_id,
        mode: timer.value.mode,
        started_at: localInput(timer.value.started_at),
        hours: Math.floor(minutes / 60),
        minutes: minutes % 60,
        fromTimer: true,
    };
    dialog.value = true;
}

function edit(item) {
    editing.value = item;
    initial.value = item.form;
    dialog.value = true;
}

async function save({ data, form, summary }) {
    const clientUuid = editing.value?.client_uuid ?? newClientUuid();

    await saveToQueue({
        client_uuid: clientUuid,
        created_at: editing.value?.created_at ?? new Date().toISOString(),
        data: { ...data, client_uuid: clientUuid },
        form,
        summary,
    });

    if (initial.value?.fromTimer) {
        clearTimer();
    }

    toast.success(
        fieldQueue.online ? 'Intervento salvato: lo invio adesso.' : 'Intervento salvato sul telefono: lo invio appena torna la connessione.',
    );
    syncQueue();
}

async function discard(item) {
    if (
        await confirm({
            title: "Eliminare l'intervento dal telefono?",
            message: 'Non è ancora stato inviato: andrà perso.',
            confirmText: 'Elimina',
            color: 'error',
        })
    ) {
        await removeFromQueue(item.client_uuid);
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Interventi sul campo"
            subtitle="Funziona anche senza connessione: gli interventi si inviano da soli quando torna la rete."
        />

        <v-alert v-if="!fieldQueue.online" type="warning" variant="tonal" class="mb-4" icon="mdi-cloud-off-outline">
            Sei offline. Puoi registrare interventi e firme: restano sul telefono fino al ritorno della connessione. Clienti e listino sono aggiornati
            al {{ dateTime(cachedAt) }}.
        </v-alert>
        <v-alert v-if="fieldQueue.needsLogin" type="error" variant="tonal" class="mb-4">
            La sessione è scaduta: accedi di nuovo per inviare gli interventi salvati sul telefono (non vanno persi).
            <template #append>
                <v-btn variant="text" :href="route('login')">Accedi</v-btn>
            </template>
        </v-alert>
        <v-alert v-if="serverTimer" type="info" variant="tonal" class="mb-4" icon="mdi-timer-outline">
            Hai un timer avviato dalla barra in alto ({{ serverTimer.number }}, {{ serverTimer.customer }}): quello richiede la connessione.
        </v-alert>

        <v-row>
            <v-col cols="12" md="6">
                <v-card class="mb-4" title="Timer" prepend-icon="mdi-timer-outline">
                    <v-card-text v-if="timer">
                        <div class="text-body-2 text-medium-emphasis">{{ timerCustomer }}</div>
                        <div class="text-h4 font-weight-bold my-2" style="font-variant-numeric: tabular-nums">
                            {{ formatElapsed(timer.started_at, now) }}
                        </div>
                        <div class="text-caption text-medium-emphasis">
                            Iniziato alle {{ new Date(timer.started_at).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }) }}
                        </div>
                    </v-card-text>
                    <v-card-text v-else>
                        <v-autocomplete v-model="timerForm.organization_id" :items="customers" label="Cliente" class="mb-2" />
                        <v-select v-model="timerForm.category_id" :items="categories" label="Categoria" class="mb-2" />
                        <v-btn-toggle v-model="timerForm.mode" mandatory color="primary" variant="outlined" divided class="w-100">
                            <v-btn v-for="mode in modes" :key="mode.value" :value="mode.value" :prepend-icon="mode.icon" class="flex-grow-1">
                                {{ mode.title }}
                            </v-btn>
                        </v-btn-toggle>
                    </v-card-text>
                    <v-card-actions>
                        <template v-if="timer">
                            <v-btn color="error" prepend-icon="mdi-stop" size="large" @click="stopTimer">Termina</v-btn>
                            <v-spacer />
                            <v-btn variant="text" @click="cancelTimer">Annulla</v-btn>
                        </template>
                        <v-btn v-else color="primary" prepend-icon="mdi-play" size="large" block @click="startTimer">Avvia</v-btn>
                    </v-card-actions>
                </v-card>

                <v-btn block size="large" variant="tonal" prepend-icon="mdi-plus" class="mb-4" @click="newIntervention">Registra un intervento</v-btn>
            </v-col>

            <v-col cols="12" md="6">
                <v-card class="mb-4" prepend-icon="mdi-cloud-upload-outline">
                    <template #title>Da inviare</template>
                    <template #append>
                        <v-btn
                            v-if="waiting.length"
                            variant="text"
                            size="small"
                            prepend-icon="mdi-cloud-sync-outline"
                            :loading="fieldQueue.syncing"
                            :disabled="!fieldQueue.online"
                            @click="syncQueue"
                        >
                            Invia ora
                        </v-btn>
                    </template>
                    <v-card-text v-if="!waiting.length" class="text-medium-emphasis">Niente da inviare: è tutto sul server.</v-card-text>
                    <v-list v-else>
                        <v-list-item v-for="item in waiting" :key="item.client_uuid" class="py-2">
                            <v-list-item-title>{{ item.summary.customer }}</v-list-item-title>
                            <v-list-item-subtitle>
                                {{ dateTime(item.summary.started_at) }} · {{ formatMinutes(item.summary.duration_minutes) }} ·
                                {{ item.summary.category }}
                                <v-icon v-if="item.summary.signed" icon="mdi-draw" size="x-small" />
                            </v-list-item-subtitle>
                            <div v-if="item.status === 'invalid'" class="text-caption text-error mt-1">
                                <div v-for="error in item.errors" :key="error">{{ error }}</div>
                            </div>
                            <template #append>
                                <v-chip v-if="item.status === 'invalid'" size="x-small" color="error" variant="tonal" class="mr-1">
                                    Da correggere
                                </v-chip>
                                <v-chip v-else size="x-small" color="warning" variant="tonal" class="mr-1">In attesa</v-chip>
                                <v-btn icon="mdi-pencil-outline" size="small" variant="text" title="Modifica" @click="edit(item)" />
                                <v-btn icon="mdi-delete-outline" size="small" variant="text" title="Elimina" @click="discard(item)" />
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>

                <v-card v-if="synced.length" class="mb-4" title="Inviati da questo telefono" prepend-icon="mdi-cloud-check-outline">
                    <v-list>
                        <v-list-item
                            v-for="item in synced"
                            :key="item.client_uuid"
                            :title="`${item.result?.number} · ${item.summary.customer}`"
                            :subtitle="`${dateTime(item.summary.started_at)} · ${formatMinutes(item.summary.duration_minutes)}`"
                            v-bind="fieldQueue.online && item.result ? link(route('interventions.show', item.result.id)) : {}"
                        />
                    </v-list>
                </v-card>

                <v-card v-if="recent.length" title="I tuoi ultimi interventi" prepend-icon="mdi-history">
                    <v-list>
                        <v-list-item
                            v-for="item in recent"
                            :key="item.id"
                            :title="`${item.number} · ${item.customer}`"
                            :subtitle="`${dateTime(item.started_at)} · ${item.duration_minutes ? formatMinutes(item.duration_minutes) : 'in corso'}`"
                            v-bind="fieldQueue.online ? link(route('interventions.show', item.id)) : {}"
                        >
                            <template #append>
                                <v-chip size="x-small" :color="item.status_color" variant="tonal">{{ item.status_label }}</v-chip>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>
        </v-row>

        <FieldInterventionDialog
            v-model="dialog"
            :initial="initial"
            :errors="editing?.errors ?? []"
            :customers="customers"
            :categories="categories"
            :modes="modes"
            :products="products"
            :rounding="rounding"
            @save="save"
        />
    </div>
</template>
