<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { isInternalUrl, relativeTime, formatDateTime } from '@/utils/format';

const props = defineProps({
    notification: { type: Object, required: true },
    /** Compact rendering for the app bar dropdown. */
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['navigate']);

const unread = computed(() => !props.notification.read_at);

const options = { preserveScroll: true, preserveState: true };

function markAsRead() {
    return new Promise((resolve) => {
        if (!unread.value) {
            return resolve();
        }

        router.patch(route('notifications.read', props.notification.id), {}, { ...options, onFinish: resolve });
    });
}

function markAsUnread() {
    router.patch(route('notifications.unread', props.notification.id), {}, options);
}

function destroy() {
    router.delete(route('notifications.destroy', props.notification.id), options);
}

async function open() {
    await markAsRead();

    const url = props.notification.action_url;

    if (!url) {
        return;
    }

    emit('navigate');

    if (isInternalUrl(url)) {
        router.visit(url);
    } else {
        window.open(url, '_blank', 'noopener');
    }
}
</script>

<template>
    <v-list-item :class="{ 'notification--unread': unread }" :lines="compact ? 'two' : 'three'" class="py-3" @click="open">
        <template #prepend>
            <v-avatar :color="notification.color" variant="tonal" :size="compact ? 36 : 44">
                <v-icon :icon="notification.icon" :size="compact ? 20 : 24" />
            </v-avatar>
        </template>

        <v-list-item-title :class="{ 'font-weight-bold': unread }" class="text-wrap">
            {{ notification.title }}
        </v-list-item-title>
        <v-list-item-subtitle class="text-pre-line">{{ notification.message }}</v-list-item-subtitle>
        <div class="text-caption text-medium-emphasis mt-1" :title="formatDateTime(notification.created_at)">
            {{ relativeTime(notification.created_at) }}
            <template v-if="notification.sender">· da {{ notification.sender }}</template>
            <template v-if="!compact && notification.action_url">
                ·
                <span class="text-primary">{{ notification.action_label || 'Apri' }}</span>
            </template>
        </div>

        <template #append>
            <div class="d-flex align-center ga-1">
                <v-badge v-if="unread" dot inline color="primary" />
                <v-menu v-if="!compact" location="bottom end">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" variant="text" size="small" @click.stop />
                    </template>
                    <v-list density="compact">
                        <v-list-item v-if="unread" prepend-icon="mdi-email-open-outline" title="Segna come letta" @click="markAsRead" />
                        <v-list-item v-else prepend-icon="mdi-email-outline" title="Segna come non letta" @click="markAsUnread" />
                        <v-list-item prepend-icon="mdi-delete-outline" base-color="error" title="Elimina" @click="destroy" />
                    </v-list>
                </v-menu>
            </div>
        </template>
    </v-list-item>
</template>

<style scoped>
.notification--unread {
    background: rgba(var(--v-theme-primary), 0.06);
}
</style>
