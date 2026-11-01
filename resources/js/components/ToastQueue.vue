<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

const { queue, push } = useToast();
const page = usePage();

// Fortify reports outcomes through `session('status')`.
const fortifyStatus = {
    'profile-information-updated': 'Profilo aggiornato.',
    'password-updated': 'Password aggiornata.',
    'two-factor-authentication-enabled': 'Autenticazione a due fattori attivata: conferma con il codice.',
    'two-factor-authentication-confirmed': 'Autenticazione a due fattori attiva.',
    'two-factor-authentication-disabled': 'Autenticazione a due fattori disattivata.',
    'recovery-codes-generated': 'Nuovi codici di recupero generati.',
    'verification-link-sent': 'Nuovo link di verifica inviato al tuo indirizzo email.',
};

// The initial page's flash data may be reported twice (page props + "flash" event).
let last = { key: null, at: 0 };

function showFlash(flash) {
    const key = JSON.stringify(flash ?? {});

    if (key === '{}' || (key === last.key && Date.now() - last.at < 1000)) {
        return;
    }

    last = { key, at: Date.now() };

    if (flash.toast?.message) {
        push(flash.toast.message, flash.toast.type ?? 'info');
    }
}

function showStatus(status) {
    if (status && fortifyStatus[status]) {
        push(fortifyStatus[status], 'success');
    }
}

let stopFlash;
let stopSuccess;

onMounted(() => {
    // Flash data of the very first page load.
    showFlash(page.flash);
    showStatus(page.props.status);

    stopFlash = router.on('flash', (event) => showFlash(event.detail.flash));
    stopSuccess = router.on('success', (event) => showStatus(event.detail.page.props.status));
});

onBeforeUnmount(() => {
    stopFlash?.();
    stopSuccess?.();
});
</script>

<template>
    <v-snackbar-queue v-model="queue" location="bottom right" closable timer="bottom" />
</template>
