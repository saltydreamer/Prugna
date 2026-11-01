<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import NotificationItem from './NotificationItem.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const page = usePage();
const { link } = useInertiaLink();
const open = ref(false);

const unreadCount = computed(() => page.props.inbox?.unread_count ?? 0);
const latest = computed(() => page.props.inbox?.latest ?? []);

function markAllAsRead() {
    router.post(route('notifications.read-all'), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <v-menu v-model="open" location="bottom end" :close-on-content-click="false" max-width="420" width="100%">
        <template #activator="{ props: activator }">
            <v-btn v-bind="activator" icon variant="text" aria-label="Notifiche">
                <v-badge :model-value="unreadCount > 0" :content="unreadCount > 99 ? '99+' : unreadCount" color="error">
                    <v-icon icon="mdi-bell-outline" />
                </v-badge>
            </v-btn>
        </template>

        <v-card>
            <v-card-item>
                <v-card-title class="text-subtitle-1 font-weight-bold">Notifiche</v-card-title>
                <v-card-subtitle>
                    {{ unreadCount ? `${unreadCount} da leggere` : 'Sei in pari' }}
                </v-card-subtitle>
                <template #append>
                    <v-btn v-if="unreadCount" size="small" variant="text" color="primary" @click="markAllAsRead">Segna tutte come lette</v-btn>
                </template>
            </v-card-item>
            <v-divider />

            <v-list v-if="latest.length" class="py-0" max-height="420" style="overflow-y: auto">
                <template v-for="(notification, index) in latest" :key="notification.id">
                    <v-divider v-if="index > 0" />
                    <NotificationItem :notification="notification" compact @navigate="open = false" />
                </template>
            </v-list>
            <v-empty-state v-else icon="mdi-bell-sleep-outline" title="Nessuna notifica" size="64" class="py-8" />

            <v-divider />
            <v-card-actions>
                <v-btn block color="primary" v-bind="link(route('notifications.index'))" @click="open = false">Apri il centro notifiche</v-btn>
            </v-card-actions>
        </v-card>
    </v-menu>
</template>
