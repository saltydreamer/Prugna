<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import UserAvatar from '@/components/UserAvatar.vue';

defineProps({
    user: { type: Object, required: true },
});

const input = ref(null);
const form = useForm({ avatar: null });

function upload(event) {
    const [file] = event.target.files ?? [];

    if (!file) {
        return;
    }

    form.avatar = file;
    form.post(route('profile.avatar.update'), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            form.reset();
            event.target.value = '';
        },
    });
}

function remove() {
    router.delete(route('profile.avatar.destroy'), { preserveScroll: true });
}
</script>

<template>
    <v-card title="Immagine del profilo" subtitle="JPG, PNG o WebP, massimo 2 MB.">
        <v-card-text class="d-flex flex-wrap align-center ga-4">
            <UserAvatar :user="user" size="80" />
            <div class="d-flex flex-wrap ga-2">
                <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" hidden @change="upload" />
                <v-btn color="primary" variant="tonal" prepend-icon="mdi-upload" :loading="form.processing" @click="input.click()">
                    Carica immagine
                </v-btn>
                <v-btn v-if="user.avatar_url" variant="text" color="error" prepend-icon="mdi-delete-outline" @click="remove">Rimuovi</v-btn>
            </div>
            <div v-if="form.errors.avatar" class="text-error text-body-2 w-100">{{ form.errors.avatar }}</div>
        </v-card-text>
    </v-card>
</template>
