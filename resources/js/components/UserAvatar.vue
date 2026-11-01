<script setup>
defineProps({
    user: { type: Object, required: true },
    size: { type: [Number, String], default: 40 },
});
</script>

<template>
    <v-avatar :size="size" :color="user.avatar_url ? undefined : 'primary'">
        <v-img v-if="user.avatar_url" :src="user.avatar_url" :alt="user.name" cover />
        <span v-else class="font-weight-medium" :style="{ fontSize: `${Number(size) * 0.4}px` }">
            {{ user.initials || '?' }}
        </span>
    </v-avatar>
</template>
