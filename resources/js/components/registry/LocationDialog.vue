<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { countries } from '@/utils/countries';

const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, required: true },
    /** Location to edit, or null to create a new one. */
    location: { type: Object, default: null },
    types: { type: Array, required: true },
});

const empty = {
    name: '',
    type: 'operational',
    is_primary: false,
    address: '',
    postal_code: '',
    city: '',
    province: '',
    country: 'IT',
    email: '',
    phone: '',
    notes: '',
};

const form = useForm({ ...empty });
const isNew = computed(() => !props.location);
const italian = computed(() => form.country === 'IT');

// Reset the form every time the dialog opens.
watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.defaults(props.location ? Object.fromEntries(Object.keys(empty).map((key) => [key, props.location[key] ?? ''])) : { ...empty });
    form.reset();
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    if (isNew.value) {
        form.post(route('registry.organizations.locations.store', props.organizationId), options);
    } else {
        form.put(route('registry.organizations.locations.update', [props.organizationId, props.location.id]), options);
    }
}
</script>

<template>
    <v-dialog v-model="open" max-width="720" scrollable>
        <v-card :title="isNew ? 'Nuova sede' : 'Modifica sede'">
            <v-divider />
            <v-card-text>
                <v-form id="location-form" @submit.prevent="submit">
                    <v-row dense>
                        <v-col cols="12" md="7">
                            <v-text-field
                                v-model="form.name"
                                label="Nome"
                                placeholder="Es. Sede centrale, Magazzino Bologna"
                                :error-messages="form.errors.name"
                                autofocus
                            />
                        </v-col>
                        <v-col cols="12" md="5">
                            <v-select v-model="form.type" :items="types" label="Tipo" :error-messages="form.errors.type" />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field v-model="form.address" label="Indirizzo" placeholder="Via Roma 1" :error-messages="form.errors.address" />
                        </v-col>
                        <v-col cols="4" md="2">
                            <v-text-field
                                v-model="form.postal_code"
                                label="CAP"
                                :maxlength="italian ? 5 : 16"
                                :error-messages="form.errors.postal_code"
                            />
                        </v-col>
                        <v-col cols="8" md="5">
                            <v-text-field v-model="form.city" label="Città" :error-messages="form.errors.city" />
                        </v-col>
                        <v-col cols="4" md="2">
                            <v-text-field
                                v-model="form.province"
                                label="Prov."
                                :maxlength="italian ? 2 : 8"
                                :error-messages="form.errors.province"
                                @update:model-value="(value) => (form.province = (value ?? '').toUpperCase())"
                            />
                        </v-col>
                        <v-col cols="8" md="3">
                            <v-autocomplete v-model="form.country" :items="countries" label="Nazione" :error-messages="form.errors.country" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.email" label="Email" type="email" :error-messages="form.errors.email" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.phone" label="Telefono" type="tel" :error-messages="form.errors.phone" />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea v-model="form.notes" label="Note" rows="2" auto-grow :error-messages="form.errors.notes" />
                        </v-col>
                        <v-col cols="12">
                            <v-switch
                                v-model="form.is_primary"
                                label="Sede principale"
                                :disabled="location?.is_primary"
                                :messages="location?.is_primary ? 'Per cambiarla, imposta come principale un\'altra sede.' : ''"
                            />
                        </v-col>
                    </v-row>
                </v-form>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn type="submit" form="location-form" color="primary" variant="flat" :loading="form.processing">
                    {{ isNew ? 'Aggiungi sede' : 'Salva' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
