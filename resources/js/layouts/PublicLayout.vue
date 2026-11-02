<script setup>
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ToastQueue from '@/components/ToastQueue.vue';
</script>

<template>
    <v-app>
        <v-main>
            <v-container class="py-6 py-md-10" style="max-width: 980px">
                <slot />
            </v-container>
        </v-main>

        <ToastQueue />
        <ConfirmDialog />
    </v-app>
</template>
