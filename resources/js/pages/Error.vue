<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    status: { type: Number, required: true },
});

const page = usePage();
const { link } = useInertiaLink();

const content = computed(
    () =>
        ({
            403: { icon: 'mdi-lock-outline', title: 'Accesso negato', text: 'Non hai i permessi per accedere a questa pagina.' },
            404: {
                icon: 'mdi-map-marker-question-outline',
                title: 'Pagina non trovata',
                text: 'La pagina che cerchi non esiste o è stata spostata.',
            },
            429: { icon: 'mdi-timer-sand', title: 'Troppe richieste', text: 'Hai effettuato troppe richieste. Riprova tra qualche istante.' },
            500: { icon: 'mdi-alert-octagon-outline', title: 'Errore del server', text: 'Si è verificato un errore imprevisto. Riprova più tardi.' },
            503: { icon: 'mdi-wrench-outline', title: 'Manutenzione in corso', text: 'Il servizio tornerà disponibile a breve.' },
        })[props.status] ?? { icon: 'mdi-alert-outline', title: 'Errore', text: 'Si è verificato un errore.' },
);

const homeUrl = computed(() => (page.props.auth?.user ? route('dashboard') : route('login')));
</script>

<template>
    <Head :title="content.title" />

    <v-empty-state :icon="content.icon" :headline="String(status)" :title="content.title" :text="content.text" class="py-12">
        <template #actions>
            <v-btn color="primary" v-bind="link(homeUrl)">Torna alla home</v-btn>
        </template>
    </v-empty-state>
</template>
