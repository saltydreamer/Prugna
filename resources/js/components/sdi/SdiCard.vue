<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';

/**
 * SDI status of an issued invoice: send, resend after a rejection, read the outcome, history.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    /** { enabled, environment, transmissions: [...], external, can_mark_to_send, can_mark_sent_outside, problems } */
    sdi: { type: Object, required: true },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const { can } = usePermissions();
const sending = ref(false);
const refreshing = ref(false);

const latest = computed(() => props.sdi.transmissions[0] ?? null);
const problems = computed(() => props.sdi.problems ?? []);
const canSend = computed(
    () => can('invoices.issue') && props.sdi.enabled && !props.sdi.external && !problems.value.length && (!latest.value || latest.value.can_resend),
);
const dateTime = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' }) : '');

function send() {
    sending.value = true;
    router.post(route('sales.invoices.sdi.send', props.invoice.id), {}, { preserveScroll: true, onFinish: () => (sending.value = false) });
}

const simulations = [
    { value: 'RC', title: 'Consegnata (RC)' },
    { value: 'MC', title: 'Mancata consegna (MC)' },
    { value: 'NS', title: 'Scartata (NS)' },
    { value: 'DT', title: 'Decorrenza termini (DT)' },
];

function simulate(notification) {
    router.post(route('sales.invoices.sdi.simulate', props.invoice.id), { notification }, { preserveScroll: true });
}

/** Transmitted outside Prugna (true) or still to send (false). */
async function markExternal(external) {
    const confirmed = await confirm(
        external
            ? {
                  title: 'Segnare come già trasmessa?',
                  message:
                      "Usalo se l'hai già inviata allo SDI in un altro modo (ad esempio caricandola su «Fatture e corrispettivi»): Prugna non la invierà.",
                  confirmText: 'Segna come trasmessa',
              }
            : {
                  title: 'Segnare come da inviare?',
                  message:
                      'Usalo solo se la fattura non è mai stata trasmessa allo SDI, nemmeno da Dolibarr: un secondo invio dello stesso numero verrebbe scartato. I dati del cliente sulla fattura vengono aggiornati con quelli attuali.',
                  confirmText: 'Segna come da inviare',
              },
    );

    if (confirmed) {
        router.put(route('sales.invoices.sdi.external', props.invoice.id), { external }, { preserveScroll: true });
    }
}

function refresh() {
    refreshing.value = true;
    router.post(route('sales.invoices.sdi.refresh', props.invoice.id), {}, { preserveScroll: true, onFinish: () => (refreshing.value = false) });
}
</script>

<template>
    <v-card title="Sistema di Interscambio" prepend-icon="mdi-bank-transfer">
        <template v-if="latest" #append>
            <v-chip :color="latest.status_color" :prepend-icon="latest.status_icon" variant="tonal">{{ latest.status_label }}</v-chip>
        </template>
        <v-card-text>
            <template v-if="sdi.external && !latest">
                <div class="text-body-2 text-medium-emphasis">
                    Trasmessa allo SDI fuori da Prugna (ad esempio emessa in Dolibarr): Prugna non la invia. Se in realtà non è mai partita, segnala
                    come da inviare.
                </div>
            </template>

            <template v-else-if="!sdi.enabled && !latest">
                <div class="text-body-2 text-medium-emphasis mb-3">
                    L'invio automatico allo SDI non è attivo: scarica l'XML e caricalo su Fatture e Corrispettivi, oppure collega Openapi.
                </div>
                <v-btn
                    v-if="can('settings.manage')"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-cog-outline"
                    v-bind="link(route('settings.einvoicing.index'))"
                >
                    Configura
                </v-btn>
            </template>

            <template v-else-if="!latest">
                <div class="text-body-2 text-medium-emphasis">La fattura non è ancora stata inviata allo SDI.</div>
                <v-alert v-if="problems.length" type="warning" variant="tonal" density="compact" class="mt-3" title="Prima di inviarla">
                    <ul class="ml-4 text-body-2">
                        <li v-for="problem in problems" :key="problem">{{ problem }}</li>
                    </ul>
                </v-alert>
            </template>

            <template v-else>
                <v-alert v-if="latest.status === 'rejected'" type="error" variant="tonal" class="mb-3">
                    <div class="font-weight-medium mb-1">Scartata: la fattura non risulta emessa.</div>
                    <div v-for="error in latest.errors" :key="error" class="text-body-2">• {{ error }}</div>
                    <div class="text-caption mt-2">
                        Correggi i dati (di solito l'anagrafica del cliente) e inviala di nuovo entro 5 giorni, con lo stesso numero e la stessa data.
                    </div>
                </v-alert>
                <v-alert v-else-if="latest.status === 'error'" type="error" variant="tonal" class="mb-3">{{ latest.message }}</v-alert>
                <v-alert v-else-if="latest.status === 'not_delivered'" type="warning" variant="tonal" class="mb-3">
                    Fattura emessa ma non recapitata: è nel cassetto fiscale del cliente. Inviagli una copia di cortesia.
                </v-alert>
                <v-alert v-else-if="latest.status === 'refused'" type="error" variant="tonal" class="mb-3">
                    Rifiutata dalla pubblica amministrazione{{ latest.message ? `: ${latest.message}` : '' }}. Emetti una nota di credito se serve.
                </v-alert>

                <div class="text-body-2">
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">File</span>
                        <span>{{ latest.filename }}</span>
                    </div>
                    <div v-if="latest.sent_at" class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Inviata</span>
                        <span>{{ dateTime(latest.sent_at) }}</span>
                    </div>
                    <div v-if="latest.outcome_at" class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Esito</span>
                        <span>{{ dateTime(latest.outcome_at) }}</span>
                    </div>
                    <div v-if="latest.environment === 'test'" class="text-caption text-warning mt-1">
                        Ambiente di prova: la fattura non ha valore fiscale.
                    </div>
                </div>

                <template v-if="sdi.transmissions.length > 1">
                    <div class="text-overline mt-3">Invii precedenti</div>
                    <div v-for="item in sdi.transmissions.slice(1)" :key="item.id" class="d-flex justify-space-between text-caption">
                        <span>{{ dateTime(item.created_at) }} · {{ item.filename }}</span>
                        <span :class="`text-${item.status_color}`">{{ item.status_label }}</span>
                    </div>
                </template>
            </template>
        </v-card-text>
        <v-card-actions v-if="canSend || latest?.has_uuid || sdi.can_mark_to_send || sdi.can_mark_sent_outside" class="flex-wrap">
            <v-btn v-if="canSend" color="primary" prepend-icon="mdi-send-outline" :loading="sending" @click="send">
                {{ latest ? 'Invia di nuovo' : 'Invia allo SDI' }}
            </v-btn>
            <v-btn v-if="latest?.has_uuid" variant="text" prepend-icon="mdi-refresh" :loading="refreshing" @click="refresh">Aggiorna esito</v-btn>
            <v-btn v-if="sdi.can_mark_to_send" color="primary" variant="tonal" prepend-icon="mdi-send-clock-outline" @click="markExternal(false)">
                Segna come da inviare
            </v-btn>
            <v-btn v-if="sdi.can_mark_sent_outside && !latest" variant="text" size="small" @click="markExternal(true)">Già trasmessa altrove</v-btn>
            <v-menu v-if="latest?.has_uuid && latest.environment === 'test' && can('invoices.issue')">
                <template #activator="{ props: activator }">
                    <v-btn v-bind="activator" variant="text" prepend-icon="mdi-flask-outline">Simula esito</v-btn>
                </template>
                <v-list density="compact">
                    <v-list-subheader>Solo in prova</v-list-subheader>
                    <v-list-item v-for="item in simulations" :key="item.value" :title="item.title" @click="simulate(item.value)" />
                </v-list>
            </v-menu>
        </v-card-actions>
    </v-card>
</template>
