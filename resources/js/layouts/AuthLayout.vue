<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ToastQueue from '@/components/ToastQueue.vue';

const page = usePage();
const appName = computed(() => page.props.app?.name ?? 'Laravel');
</script>

<template>
    <v-app>
        <v-main class="d-flex align-center">
            <v-container class="py-10" style="max-width: 480px">
                <div class="d-flex flex-column align-center mb-6">
                    <v-avatar color="primary" rounded="lg" size="56" class="mb-3">
                        <v-icon icon="mdi-cube-outline" size="32" />
                    </v-avatar>
                    <div class="text-h6 font-weight-bold">{{ appName }}</div>
                </div>

                <slot />
            </v-container>
        </v-main>

        <ToastQueue />
        <ConfirmDialog />
    </v-app>
</template>
