<script setup>
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import NotificationItem from '@/components/NotificationItem.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    notifications: { type: Object, required: true },
    filter: { type: String, default: 'all' },
    counts: { type: Object, required: true },
});

const { confirm } = useConfirm();

const filters = [
    { value: 'all', title: 'Tutte' },
    { value: 'unread', title: 'Da leggere' },
    { value: 'read', title: 'Lette' },
];

function load(params) {
    router.get(route('notifications.index'), { filter: props.filter, ...params }, { preserveState: true, preserveScroll: true });
}

function markAllAsRead() {
    router.post(route('notifications.read-all'), {}, { preserveScroll: true });
}

async function destroyRead() {
    const confirmed = await confirm({
        title: 'Eliminare le notifiche lette?',
        message: "L'operazione non può essere annullata.",
        confirmText: 'Elimina',
        color: 'error',
        icon: 'mdi-delete-sweep-outline',
    });

    if (confirmed) {
        router.delete(route('notifications.destroy-read'), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader title="Centro notifiche" subtitle="Tutti gli aggiornamenti che riguardano il tuo account.">
        <template #actions>
            <v-btn variant="tonal" color="primary" prepend-icon="mdi-email-open-multiple-outline" :disabled="!counts.unread" @click="markAllAsRead">
                Segna tutte come lette
            </v-btn>
            <v-btn
                variant="tonal"
                color="error"
                prepend-icon="mdi-delete-sweep-outline"
                :disabled="counts.all === counts.unread"
                @click="destroyRead"
            >
                Elimina lette
            </v-btn>
        </template>
    </PageHeader>

    <v-card>
        <v-tabs :model-value="filter" color="primary" @update:model-value="(value) => load({ filter: value, page: 1 })">
            <v-tab v-for="item in filters" :key="item.value" :value="item.value">
                {{ item.title }}
                <v-badge v-if="item.value === 'unread' && counts.unread" :content="counts.unread" color="error" inline />
            </v-tab>
        </v-tabs>
        <v-divider />

        <v-list v-if="notifications.data.length" class="py-0">
            <template v-for="(notification, index) in notifications.data" :key="notification.id">
                <v-divider v-if="index > 0" />
                <NotificationItem :notification="notification" />
            </template>
        </v-list>
        <v-empty-state
            v-else
            icon="mdi-bell-check-outline"
            :title="filter === 'unread' ? 'Nessuna notifica da leggere' : 'Nessuna notifica'"
            text="Quando ci saranno novità le troverai qui."
        />

        <template v-if="notifications.meta.last_page > 1">
            <v-divider />
            <v-pagination
                :model-value="notifications.meta.current_page"
                :length="notifications.meta.last_page"
                total-visible="7"
                class="py-2"
                @update:model-value="(page) => load({ page })"
            />
        </template>
    </v-card>
</template>
