<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';

defineProps({
    roles: { type: Array, required: true },
    users: { type: Array, required: true },
    levels: { type: Array, required: true },
});

const levelOptions = {
    info: { title: 'Informazione', icon: 'mdi-bullhorn-outline' },
    success: { title: 'Successo', icon: 'mdi-check-circle-outline' },
    warning: { title: 'Avviso', icon: 'mdi-alert-outline' },
    error: { title: 'Urgente', icon: 'mdi-alert-octagon-outline' },
};

const form = useForm({
    title: '',
    message: '',
    level: 'info',
    action_url: '',
    audience: 'all',
    roles: [],
    users: [],
    send_mail: false,
});

const preview = computed(() => ({
    title: form.title || "Titolo dell'annuncio",
    message: form.message || 'Il testo del messaggio apparirà qui.',
    color: form.level,
    icon: levelOptions[form.level]?.icon,
}));

function submit() {
    form.transform((data) => ({ ...data, action_url: data.action_url || null })).post(route('admin.announcements.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <PageHeader title="Invia un annuncio" subtitle="Invia una notifica a tutti gli utenti, a uno o più ruoli o a utenti specifici." />

    <v-row>
        <v-col cols="12" lg="7">
            <v-card>
                <v-form @submit.prevent="submit">
                    <v-card-text>
                        <v-text-field v-model="form.title" label="Titolo" counter="150" :error-messages="form.errors.title" class="mb-2" />
                        <v-textarea
                            v-model="form.message"
                            label="Messaggio"
                            rows="4"
                            auto-grow
                            counter="2000"
                            :error-messages="form.errors.message"
                            class="mb-2"
                        />

                        <v-row>
                            <v-col cols="12" md="5">
                                <v-select
                                    v-model="form.level"
                                    :items="levels.map((value) => ({ value, ...levelOptions[value] }))"
                                    item-props
                                    label="Tipo"
                                    :error-messages="form.errors.level"
                                >
                                    <template #selection="{ item }">
                                        <v-icon :icon="item.icon" :color="item.value" class="mr-2" />
                                        {{ item.title }}
                                    </template>
                                </v-select>
                            </v-col>
                            <v-col cols="12" md="7">
                                <v-text-field
                                    v-model="form.action_url"
                                    label="Link (facoltativo)"
                                    placeholder="https://..."
                                    :error-messages="form.errors.action_url"
                                />
                            </v-col>
                        </v-row>

                        <div class="text-subtitle-1 font-weight-medium mt-2">Destinatari</div>
                        <v-radio-group v-model="form.audience" inline hide-details class="mb-2">
                            <v-radio label="Tutti gli utenti attivi" value="all" />
                            <v-radio label="Per ruolo" value="roles" />
                            <v-radio label="Utenti specifici" value="users" />
                        </v-radio-group>

                        <v-autocomplete
                            v-if="form.audience === 'roles'"
                            v-model="form.roles"
                            :items="roles"
                            label="Ruoli"
                            multiple
                            chips
                            closable-chips
                            :error-messages="form.errors.roles"
                        />
                        <v-autocomplete
                            v-if="form.audience === 'users'"
                            v-model="form.users"
                            :items="users"
                            item-props
                            label="Utenti"
                            multiple
                            chips
                            closable-chips
                            :error-messages="form.errors.users"
                        />

                        <v-switch v-model="form.send_mail" label="Invia anche via email (agli utenti che lo hanno abilitato)" class="mt-2" />
                    </v-card-text>
                    <v-card-actions class="px-4 pb-4">
                        <v-spacer />
                        <v-btn type="submit" color="primary" variant="flat" prepend-icon="mdi-send-outline" :loading="form.processing">Invia</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-col>

        <v-col cols="12" lg="5">
            <v-card title="Anteprima" subtitle="Come apparirà nel centro notifiche">
                <v-list class="py-0">
                    <v-list-item lines="three" class="py-3">
                        <template #prepend>
                            <v-avatar :color="preview.color" variant="tonal" size="44"><v-icon :icon="preview.icon" /></v-avatar>
                        </template>
                        <v-list-item-title class="font-weight-bold text-wrap">{{ preview.title }}</v-list-item-title>
                        <v-list-item-subtitle class="text-pre-line">{{ preview.message }}</v-list-item-subtitle>
                    </v-list-item>
                </v-list>
            </v-card>
        </v-col>
    </v-row>
</template>
