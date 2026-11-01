<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';
import { http, HttpError } from '@/utils/http';

const props = defineProps({
    twoFactor: { type: Object, required: true },
});

const { confirm } = useConfirm();
const toast = useToast();

const busy = ref(false);
const qrCode = ref(null);
const setupKey = ref(null);
const recoveryCodes = ref([]);
const code = ref('');
const codeError = ref(null);

// Fortify's two factor endpoints require a recently confirmed password.
const passwordDialog = ref(false);
const password = ref('');
const passwordError = ref(null);
let passwordResolver = null;

const status = computed(() => {
    if (props.twoFactor.enabled && (props.twoFactor.confirmed || !props.twoFactor.requiresConfirmation)) {
        return 'enabled';
    }

    return props.twoFactor.enabled ? 'confirming' : 'disabled';
});

async function ensurePasswordConfirmed() {
    const { confirmed } = await http.get(route('password.confirmation'));

    if (confirmed) {
        return true;
    }

    password.value = '';
    passwordError.value = null;
    passwordDialog.value = true;

    return new Promise((resolve) => {
        passwordResolver = resolve;
    });
}

async function submitPassword() {
    try {
        await http.post(route('password.confirm.store'), { password: password.value });
        passwordDialog.value = false;
        passwordResolver?.(true);
    } catch (error) {
        passwordError.value = error instanceof HttpError ? (error.errors.password?.[0] ?? error.message) : 'Errore imprevisto.';
    }
}

function cancelPassword() {
    passwordDialog.value = false;
    passwordResolver?.(false);
}

/** Runs an action after password confirmation, handling the loading state and errors. */
async function withConfirmedPassword(action) {
    busy.value = true;

    try {
        if (await ensurePasswordConfirmed()) {
            await action();
        }
    } catch (error) {
        toast.error(error.message || 'Operazione non riuscita.');
    } finally {
        busy.value = false;
    }
}

function reloadState() {
    return new Promise((resolve) => router.reload({ only: ['twoFactor'], onFinish: resolve }));
}

async function loadSetup() {
    const [qr, key] = await Promise.all([http.get(route('two-factor.qr-code')), http.get(route('two-factor.secret-key'))]);
    qrCode.value = qr.svg;
    setupKey.value = key.secretKey;
}

async function loadRecoveryCodes() {
    recoveryCodes.value = await http.get(route('two-factor.recovery-codes'));
}

const enable = () =>
    withConfirmedPassword(async () => {
        await http.post(route('two-factor.enable'));
        await loadSetup();
        await reloadState();
    });

const resumeSetup = () => withConfirmedPassword(loadSetup);

async function confirmCode() {
    codeError.value = null;
    busy.value = true;

    try {
        await http.post(route('two-factor.confirm'), { code: code.value });
        qrCode.value = null;
        setupKey.value = null;
        code.value = '';
        await loadRecoveryCodes();
        await reloadState();
        toast.success('Autenticazione a due fattori attiva. Conserva i codici di recupero.');
    } catch (error) {
        codeError.value = error instanceof HttpError ? (error.errors.code?.[0] ?? error.message) : 'Errore imprevisto.';
    } finally {
        busy.value = false;
    }
}

const showRecoveryCodes = () => withConfirmedPassword(loadRecoveryCodes);

const regenerateRecoveryCodes = () =>
    withConfirmedPassword(async () => {
        await http.post(route('two-factor.regenerate-recovery-codes'));
        await loadRecoveryCodes();
        toast.success('Nuovi codici di recupero generati.');
    });

async function disable() {
    const confirmed = await confirm({
        title: "Disattivare l'autenticazione a due fattori?",
        message: 'Il tuo account sarà protetto solo dalla password.',
        confirmText: 'Disattiva',
        color: 'error',
    });

    if (!confirmed) {
        return;
    }

    await withConfirmedPassword(async () => {
        await http.delete(route('two-factor.disable'));
        qrCode.value = null;
        setupKey.value = null;
        recoveryCodes.value = [];
        await reloadState();
        toast.success('Autenticazione a due fattori disattivata.');
    });
}

function copyRecoveryCodes() {
    navigator.clipboard?.writeText(recoveryCodes.value.join('\n'));
    toast.info('Codici copiati negli appunti.');
}
</script>

<template>
    <v-card>
        <v-card-item>
            <v-card-title>Autenticazione a due fattori</v-card-title>
            <v-card-subtitle class="text-wrap">
                Aggiungi un secondo livello di sicurezza con un'app di autenticazione (Google Authenticator, 1Password, Authy...).
            </v-card-subtitle>
            <template #append>
                <v-chip :color="status === 'enabled' ? 'success' : status === 'confirming' ? 'warning' : undefined" variant="tonal">
                    {{ status === 'enabled' ? 'Attiva' : status === 'confirming' ? 'Da confermare' : 'Non attiva' }}
                </v-chip>
            </template>
        </v-card-item>

        <v-card-text>
            <!-- Setup: QR code + confirmation code -->
            <template v-if="status === 'confirming'">
                <template v-if="qrCode">
                    <p class="mb-4">Scansiona il QR code con la tua app di autenticazione, poi inserisci il codice generato.</p>
                    <div class="d-flex flex-wrap ga-6 align-center">
                        <div class="pa-3 bg-white rounded-lg" v-html="qrCode" />
                        <div>
                            <div class="text-caption text-medium-emphasis">Chiave di configurazione</div>
                            <code class="text-body-1">{{ setupKey }}</code>
                        </div>
                    </div>
                    <div class="mt-6" style="max-width: 360px">
                        <v-otp-input v-model="code" length="6" type="number" :disabled="busy" @finish="confirmCode" />
                        <div v-if="codeError" class="text-error text-body-2">{{ codeError }}</div>
                    </div>
                </template>
                <v-alert v-else type="warning" variant="tonal">
                    La configurazione non è stata completata.
                    <template #append>
                        <v-btn variant="text" :loading="busy" @click="resumeSetup">Riprendi</v-btn>
                    </template>
                </v-alert>
            </template>

            <!-- Recovery codes -->
            <template v-if="recoveryCodes.length && status === 'enabled'">
                <v-alert type="info" variant="tonal" class="mt-4" title="Codici di recupero">
                    Conservali in un posto sicuro: ti permettono di accedere se perdi il dispositivo.
                </v-alert>
                <v-sheet rounded="lg" border class="pa-4 mt-3 d-flex flex-wrap ga-3" style="font-family: monospace">
                    <span v-for="item in recoveryCodes" :key="item" class="w-sm-auto">{{ item }}</span>
                </v-sheet>
            </template>
        </v-card-text>

        <v-card-actions class="px-4 pb-4 flex-wrap ga-2">
            <template v-if="status === 'disabled'">
                <v-btn color="primary" variant="flat" prepend-icon="mdi-shield-plus-outline" :loading="busy" @click="enable">Attiva</v-btn>
            </template>
            <template v-else-if="status === 'confirming'">
                <v-btn v-if="qrCode" color="primary" variant="flat" :loading="busy" :disabled="code.length !== 6" @click="confirmCode">
                    Conferma
                </v-btn>
                <v-btn variant="text" :disabled="busy" @click="disable">Annulla</v-btn>
            </template>
            <template v-else>
                <v-btn v-if="!recoveryCodes.length" variant="tonal" prepend-icon="mdi-key-outline" :loading="busy" @click="showRecoveryCodes">
                    Mostra codici di recupero
                </v-btn>
                <template v-else>
                    <v-btn variant="tonal" prepend-icon="mdi-content-copy" @click="copyRecoveryCodes">Copia</v-btn>
                    <v-btn variant="tonal" prepend-icon="mdi-refresh" :loading="busy" @click="regenerateRecoveryCodes">Rigenera</v-btn>
                </template>
                <v-spacer />
                <v-btn color="error" variant="text" :disabled="busy" @click="disable">Disattiva</v-btn>
            </template>
        </v-card-actions>

        <v-dialog v-model="passwordDialog" max-width="420" persistent>
            <v-card title="Conferma la password" subtitle="Per sicurezza, conferma la tua password per continuare.">
                <v-form @submit.prevent="submitPassword">
                    <v-card-text>
                        <v-text-field
                            v-model="password"
                            label="Password"
                            type="password"
                            autocomplete="current-password"
                            :error-messages="passwordError"
                            autofocus
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="cancelPassword">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat">Conferma</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </v-card>
</template>
