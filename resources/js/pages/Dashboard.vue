<script setup>
import { computed } from 'vue';
import { Deferred, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import NotificationItem from '@/components/NotificationItem.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';

defineProps({
    stats: { type: Object, required: true },
    adminStats: { type: Object, default: null },
    recentNotifications: { type: Array, default: () => [] },
});

const page = usePage();
const { link } = useInertiaLink();
const { can } = usePermissions();

const firstName = computed(() => page.props.auth.user.name.split(' ')[0]);

const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 13) return 'Buongiorno';
    if (hour < 18) return 'Buon pomeriggio';

    return 'Buonasera';
});

const adminCards = [
    { key: 'users_total', title: 'Utenti totali', icon: 'mdi-account-multiple-outline', color: 'primary' },
    { key: 'users_active', title: 'Utenti attivi', icon: 'mdi-account-check-outline', color: 'success' },
    { key: 'users_new_this_month', title: 'Nuovi questo mese', icon: 'mdi-account-plus-outline', color: 'info' },
    { key: 'roles_total', title: 'Ruoli', icon: 'mdi-shield-key-outline', color: 'secondary' },
];
</script>

<template>
    <PageHeader :title="`${greeting}, ${firstName}`" subtitle="Ecco un riepilogo del tuo account." />

    <v-row>
        <v-col cols="12" sm="6" lg="4">
            <v-card v-bind="link(route('notifications.index', { filter: 'unread' }))" class="h-100">
                <v-card-item>
                    <template #prepend>
                        <v-avatar color="primary" variant="tonal" size="48"><v-icon icon="mdi-bell-outline" /></v-avatar>
                    </template>
                    <v-card-subtitle>Notifiche da leggere</v-card-subtitle>
                    <v-card-title class="text-h4 font-weight-bold">{{ stats.unread_notifications }}</v-card-title>
                </v-card-item>
            </v-card>
        </v-col>

        <v-col cols="12" sm="6" lg="4">
            <v-card v-bind="link(route('profile.show', { tab: 'security' }))" class="h-100">
                <v-card-item>
                    <template #prepend>
                        <v-avatar :color="stats.two_factor_enabled ? 'success' : 'warning'" variant="tonal" size="48">
                            <v-icon :icon="stats.two_factor_enabled ? 'mdi-shield-check-outline' : 'mdi-shield-alert-outline'" />
                        </v-avatar>
                    </template>
                    <v-card-subtitle>Autenticazione a due fattori</v-card-subtitle>
                    <v-card-title class="text-h6 font-weight-bold">{{ stats.two_factor_enabled ? 'Attiva' : 'Non attiva' }}</v-card-title>
                </v-card-item>
            </v-card>
        </v-col>

        <v-col cols="12" lg="4">
            <v-card class="h-100">
                <v-card-item>
                    <template #prepend>
                        <v-avatar color="secondary" variant="tonal" size="48"><v-icon icon="mdi-badge-account-outline" /></v-avatar>
                    </template>
                    <v-card-subtitle>I tuoi ruoli</v-card-subtitle>
                    <div class="d-flex flex-wrap ga-1 mt-1">
                        <v-chip v-for="role in stats.roles" :key="role" color="secondary" variant="tonal">{{ role }}</v-chip>
                        <span v-if="!stats.roles.length" class="text-medium-emphasis">Nessun ruolo</span>
                    </div>
                </v-card-item>
            </v-card>
        </v-col>
    </v-row>

    <template v-if="can('users.view')">
        <h2 class="text-subtitle-1 font-weight-bold mt-8 mb-3">Amministrazione</h2>
        <Deferred data="adminStats">
            <template #fallback>
                <v-row>
                    <v-col v-for="card in adminCards" :key="card.key" cols="12" sm="6" lg="3">
                        <v-skeleton-loader type="list-item-avatar-two-line" class="rounded-lg" />
                    </v-col>
                </v-row>
            </template>

            <v-row>
                <v-col v-for="card in adminCards" :key="card.key" cols="12" sm="6" lg="3">
                    <v-card v-bind="link(route('admin.users.index'))" class="h-100">
                        <v-card-item>
                            <template #prepend>
                                <v-avatar :color="card.color" variant="tonal" size="44"><v-icon :icon="card.icon" /></v-avatar>
                            </template>
                            <v-card-subtitle>{{ card.title }}</v-card-subtitle>
                            <v-card-title class="text-h5 font-weight-bold">{{ adminStats?.[card.key] ?? 0 }}</v-card-title>
                        </v-card-item>
                    </v-card>
                </v-col>
            </v-row>
        </Deferred>
    </template>

    <v-card class="mt-8">
        <v-card-item>
            <v-card-title class="text-subtitle-1 font-weight-bold">Ultime notifiche</v-card-title>
            <template #append>
                <v-btn variant="text" color="primary" size="small" v-bind="link(route('notifications.index'))">Vedi tutte</v-btn>
            </template>
        </v-card-item>
        <v-divider />
        <v-list v-if="recentNotifications.length" class="py-0">
            <template v-for="(notification, index) in recentNotifications" :key="notification.id">
                <v-divider v-if="index > 0" />
                <NotificationItem :notification="notification" compact />
            </template>
        </v-list>
        <v-empty-state
            v-else
            icon="mdi-bell-sleep-outline"
            title="Nessuna notifica"
            text="Qui vedrai gli aggiornamenti che ti riguardano."
            size="64"
        />
    </v-card>
</template>
