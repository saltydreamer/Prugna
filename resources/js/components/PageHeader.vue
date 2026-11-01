<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    /** Sets the document <title> as well. */
    head: { type: Boolean, default: true },
});
</script>

<template>
    <Head v-if="head" :title="title" />
    <div class="d-flex flex-wrap align-center ga-4 mb-6">
        <div class="flex-grow-1">
            <h1 class="text-h5 font-weight-bold ma-0">{{ title }}</h1>
            <p v-if="subtitle" class="text-body-2 text-medium-emphasis mt-1 mb-0">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="d-flex flex-wrap ga-2">
            <slot name="actions" />
        </div>
    </div>
</template>
