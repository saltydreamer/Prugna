<script setup>
import { useConfirm } from '@/composables/useConfirm';

const { state, settle } = useConfirm();
</script>

<template>
    <v-dialog :model-value="state.open" max-width="440" @update:model-value="(open) => !open && settle(false)">
        <v-card :prepend-icon="state.icon ?? undefined" :title="state.title">
            <v-card-text v-if="state.message" class="text-pre-line">{{ state.message }}</v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="settle(false)">{{ state.cancelText }}</v-btn>
                <v-btn :color="state.color" variant="flat" @click="settle(true)">{{ state.confirmText }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
