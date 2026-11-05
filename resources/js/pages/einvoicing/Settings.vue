<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    settings: { type: Object, required: true },
    recipientCode: { type: String, required: true },
    webhookUrl: { type: String, required: true },
    prices: { type: Array, required: true },
    /** { connected, company }: the company VAT number changed after connecting. */
    fiscalMismatch: { type: Object, default: null },
});

const { link } = useInertiaLink();
const toast = useToast();

const form = useForm({
    enabled: props.settings.enabled,
    environment: props.settings.environment ?? 'test',
    username: props.settings.username ?? '',
    api_key: '',
    auto_send: props.settings.auto_send,
});

function save() {
    form.put(route('settings.einvoicing.update'), { preserveScroll: true, onSuccess: () => (form.api_key = '') });
}

const connecting = ref(false);
const connected = computed(
    () => Boolean(props.settings.connected_at) && props.settings.connected_environment === props.settings.environment && !props.fiscalMismatch,
);

const mismatchText = computed(() =>
    props.fiscalMismatch
        ? `La partita IVA dell'azienda (${props.fiscalMismatch.company}) è cambiata dopo il collegamento (era ${props.fiscalMismatch.connected}): Openapi rifiuta le fatture finché non premi «Collega», che sostituisce la registrazione con la P.IVA ${props.fiscalMismatch.connected} con quella nuova.`
        : '',
);

function connect() {
    connecting.value = true;
    router.post(route('settings.einvoicing.connect'), {}, { preserveScroll: true, onFinish: () => (connecting.value = false) });
}

const priceForm = useForm({ prices: Object.fromEntries(props.prices.map((item) => [item.endpoint, { price: item.price, free: item.free }])) });

function savePrices() {
    priceForm.put(route('settings.einvoicing.prices'), { preserveScroll: true });
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copiato.');
    } catch {
        toast.error('Copia non riuscita: selezionalo a mano.');
    }
}
</script>

<template>
    <div>
        <PageHeader title="Fatturazione elettronica" subtitle="Invio e ricezione delle fatture tramite lo SDI, con Openapi come intermediario">
            <template #actions>
                <v-btn variant="tonal" prepend-icon="mdi-chart-box-outline" v-bind="link(route('settings.einvoicing.costs'))">Costi Openapi</v-btn>
            </template>
        </PageHeader>

        <v-row>
            <v-col cols="12" lg="7">
                <v-card class="mb-6" title="Account Openapi">
                    <v-card-text>
                        <v-switch v-model="form.enabled" label="Usa Openapi per inviare e ricevere le fatture" class="mb-2" />
                        <v-btn-toggle v-model="form.environment" mandatory color="primary" variant="outlined" divided class="mb-1">
                            <v-btn value="test" prepend-icon="mdi-flask-outline">Prova (sandbox)</v-btn>
                            <v-btn value="production" prepend-icon="mdi-check-decagram-outline">Produzione</v-btn>
                        </v-btn-toggle>
                        <div class="text-caption text-medium-emphasis mb-4">
                            In prova le fatture non arrivano davvero all'Agenzia delle Entrate e non si paga nulla. Passa a produzione solo dopo le
                            prove.
                        </div>
                        <v-row dense>
                            <v-col cols="12" md="6">
                                <v-text-field
                                    v-model="form.username"
                                    label="E-mail dell'account Openapi"
                                    type="email"
                                    autocomplete="off"
                                    :error-messages="form.errors.username"
                                />
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-text-field
                                    v-model="form.api_key"
                                    label="API key"
                                    type="password"
                                    autocomplete="new-password"
                                    :placeholder="settings.has_api_key ? 'Configurata: lascia vuoto per non cambiarla' : ''"
                                    persistent-placeholder
                                    :error-messages="form.errors.api_key"
                                    hint="La trovi nella console Openapi (sezione API key). Si salva cifrata e non si mostra più."
                                    persistent-hint
                                />
                            </v-col>
                        </v-row>
                        <v-switch v-model="form.auto_send" label="Invia allo SDI quando emetti una fattura" class="mt-2" />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="primary" :loading="form.processing" @click="save">Salva</v-btn>
                    </v-card-actions>
                </v-card>

                <v-card class="mb-6" title="Collegamento" prepend-icon="mdi-link-variant">
                    <template #append>
                        <v-chip v-if="connected" color="success" variant="tonal" prepend-icon="mdi-check">
                            Collegato ({{ settings.environment === 'test' ? 'prova' : 'produzione' }})
                        </v-chip>
                        <v-chip v-else color="warning" variant="tonal">Da collegare</v-chip>
                    </template>
                    <v-card-text class="text-body-2">
                        <v-alert v-if="fiscalMismatch" type="warning" variant="tonal" class="mb-3">{{ mismatchText }}</v-alert>
                        <p class="mb-2">
                            «Collega» verifica le credenziali, registra la tua azienda su Openapi (P.IVA
                            {{ fiscalMismatch?.company ?? settings.fiscal_id ?? 'dalle impostazioni azienda' }}) e imposta l'indirizzo a cui Openapi
                            comunica esiti e fatture ricevute:
                        </p>
                        <div class="d-flex align-center ga-2 mb-2">
                            <code class="text-caption">{{ webhookUrl }}</code>
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" title="Copia" @click="copy(webhookUrl)" />
                        </div>
                        <p class="text-medium-emphasis mb-0">
                            Firma digitale e conservazione a norma non vengono attivate su Openapi: la conservazione resta quella gratuita
                            dell'Agenzia delle Entrate a cui hai aderito, che conserva tutte le fatture passate dallo SDI.
                        </p>
                    </v-card-text>
                    <v-card-actions>
                        <v-btn
                            color="primary"
                            variant="tonal"
                            prepend-icon="mdi-connection"
                            :loading="connecting"
                            :disabled="!settings.enabled || !settings.has_api_key || form.isDirty"
                            @click="connect"
                        >
                            {{ connected ? 'Ricollega' : 'Collega' }}
                        </v-btn>
                        <span v-if="form.isDirty" class="text-caption text-medium-emphasis ml-2">Salva prima le modifiche.</span>
                    </v-card-actions>
                </v-card>
            </v-col>

            <v-col cols="12" lg="5">
                <v-card class="mb-6" title="Ricevere le fatture dei fornitori" prepend-icon="mdi-inbox-arrow-down-outline">
                    <v-card-text class="text-body-2">
                        <p class="mb-2">
                            Perché le fatture dei fornitori arrivino qui, registra il codice destinatario di Openapi sul portale dell'Agenzia:
                        </p>
                        <div class="d-flex align-center ga-2 mb-3">
                            <span class="text-h6" style="letter-spacing: 0.08em">{{ recipientCode }}</span>
                            <v-btn icon="mdi-content-copy" size="x-small" variant="text" title="Copia" @click="copy(recipientCode)" />
                        </div>
                        <ol class="pl-4 mb-2">
                            <li>
                                Entra in
                                <strong>Fatture e Corrispettivi</strong>
                                con SPID/CIE.
                            </li>
                            <li>
                                Apri
                                <strong>Registrazione dell'indirizzo telematico</strong>
                                .
                            </li>
                            <li>
                                Scegli
                                <strong>Codice destinatario</strong>
                                e inserisci {{ recipientCode }}.
                            </li>
                        </ol>
                        <p class="text-medium-emphasis mb-0">
                            Da quel momento lo SDI consegna tutte le fatture ricevute a Openapi, che le passa all'app in «Fatture ricevute», anche se
                            il fornitore indica un altro codice o la PEC. Le fatture già ricevute le importi dai file scaricati dal portale.
                        </p>
                    </v-card-text>
                </v-card>

                <v-card title="Listino Openapi" subtitle="Prezzo per chiamata, IVA esclusa: serve a calcolare i costi">
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Servizio</th>
                                <th>Prezzo</th>
                                <th>Gratis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in prices" :key="item.endpoint">
                                <td class="py-1">
                                    <div class="text-body-2">{{ item.label }}</div>
                                    <div class="text-caption text-medium-emphasis">{{ item.endpoint }}</div>
                                </td>
                                <td style="width: 120px">
                                    <v-text-field
                                        v-model.number="priceForm.prices[item.endpoint].price"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        prefix="€"
                                        hide-details
                                        density="compact"
                                    />
                                </td>
                                <td style="width: 110px">
                                    <v-text-field
                                        v-model.number="priceForm.prices[item.endpoint].free"
                                        type="number"
                                        min="0"
                                        suffix="/gg"
                                        hide-details
                                        density="compact"
                                        title="Chiamate gratuite al giorno"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text class="text-caption text-medium-emphasis pb-0">
                        Prezzi base del listino Openapi: invio 0,07 €, download 0,001 €; le consultazioni sono gratuite fino alla quota giornaliera,
                        poi 0,001 €. Con un abbonamento l'invio scende fino a 0,022 €: aggiorna qui il tuo prezzo.
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="primary" variant="tonal" :loading="priceForm.processing" @click="savePrices">Salva listino</v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>
