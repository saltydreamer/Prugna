<script setup>
import { onMounted, ref } from 'vue';
import { route } from 'ziggy-js';
import { useToast } from '@/composables/useToast';
import { http } from '@/utils/http';
import { currentPushSubscription, disablePush, enablePush, installApp, pushSupported, pwa } from '@/utils/pwa';

/**
 * Push notifications on this device and installation of the app.
 */
const toast = useToast();
const supported = pushSupported();
const enabled = ref(false);
const busy = ref(false);
const error = ref('');

onMounted(async () => {
    enabled.value = Boolean(await currentPushSubscription());
});

async function toggle(value) {
    busy.value = true;
    error.value = '';

    try {
        if (value) {
            await enablePush();
            enabled.value = true;
            toast.success('Notifiche attive su questo dispositivo.');
        } else {
            await disablePush();
            enabled.value = false;
            toast.success('Notifiche disattivate su questo dispositivo.');
        }
    } catch (exception) {
        error.value = exception.message;
        enabled.value = Boolean(await currentPushSubscription());
    } finally {
        busy.value = false;
    }
}

async function test() {
    busy.value = true;

    try {
        await http.post(route('profile.push.test'));
        toast.success('Notifica di prova inviata: arriva tra pochi secondi.');
    } catch (exception) {
        toast.error(exception.message);
    } finally {
        busy.value = false;
    }
}

async function install() {
    if (await installApp()) {
        toast.success('App installata: la trovi tra le app del telefono.');
    }
}
</script>

<template>
    <v-card title="App e notifiche sul telefono" subtitle="Installa l'app e ricevi gli avvisi anche quando è chiusa.">
        <v-card-text>
            <div class="d-flex align-center flex-wrap ga-3 mb-4">
                <v-icon :icon="pwa.installed ? 'mdi-cellphone-check' : 'mdi-cellphone-arrow-down'" />
                <div class="flex-grow-1 text-body-2">
                    <template v-if="pwa.installed">L'app è installata su questo dispositivo.</template>
                    <template v-else-if="pwa.installable">
                        Puoi installare l'app su questo dispositivo: si apre come le altre app, a schermo intero.
                    </template>
                    <template v-else>Su Android apri il sito con Chrome e scegli «Installa app» o «Aggiungi a schermata Home» dal menu ⋮.</template>
                </div>
                <v-btn v-if="pwa.installable" color="primary" prepend-icon="mdi-download" @click="install">Installa</v-btn>
            </div>

            <v-switch
                v-if="supported"
                :model-value="enabled"
                :loading="busy"
                :disabled="busy"
                label="Notifiche su questo dispositivo"
                messages="Esiti dello SDI, monte ore in esaurimento, risposte ai preventivi e gli altri avvisi dell'app."
                @update:model-value="toggle"
            />
            <v-alert v-else type="info" variant="tonal">
                Questo browser non supporta le notifiche push. Su Android usa Chrome; su iPhone installa prima l'app dalla condivisione → «Aggiungi
                alla schermata Home».
            </v-alert>
            <v-alert v-if="error" type="error" variant="tonal" class="mt-3">{{ error }}</v-alert>
        </v-card-text>
        <v-card-actions v-if="enabled" class="px-4 pb-4">
            <v-spacer />
            <v-btn variant="tonal" prepend-icon="mdi-bell-ring-outline" :loading="busy" @click="test">Invia una prova</v-btn>
        </v-card-actions>
    </v-card>
</template>
