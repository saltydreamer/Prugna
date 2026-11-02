<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, required: true },
    /** Contact to edit, or null to create a new one. */
    contact: { type: Object, default: null },
    /** Locations of the organization, to link the contact to one of them. */
    locations: { type: Array, default: () => [] },
});

const empty = {
    first_name: '',
    last_name: '',
    job_title: '',
    email: '',
    phone: '',
    mobile: '',
    location_id: null,
    is_primary: false,
    notes: '',
};

const emit = defineEmits(['created']);

const form = useForm({ ...empty });
const isNew = computed(() => !props.contact);
const locationItems = computed(() => props.locations.map((location) => ({ value: location.id, title: `${location.name} · ${location.city}` })));

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.defaults(
        props.contact
            ? Object.fromEntries(Object.keys(empty).map((key) => [key, props.contact[key] ?? (key === 'location_id' ? null : '')]))
            : { ...empty },
    );
    form.reset();
});

function submit() {
    // preserveState keeps the page that opened the dialog intact (e.g. a quote being edited).
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            if (isNew.value && page.flash?.createdContact) {
                emit('created', page.flash.createdContact);
            }

            open.value = false;
        },
    };

    if (isNew.value) {
        form.post(route('registry.organizations.contacts.store', props.organizationId), options);
    } else {
        form.put(route('registry.organizations.contacts.update', [props.organizationId, props.contact.id]), options);
    }
}
</script>

<template>
    <v-dialog v-model="open" max-width="680" scrollable>
        <v-card :title="isNew ? 'Nuovo contatto' : 'Modifica contatto'">
            <v-divider />
            <v-card-text>
                <v-form id="contact-form" @submit.prevent="submit">
                    <v-row dense>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.first_name" label="Nome" :error-messages="form.errors.first_name" autofocus />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.last_name" label="Cognome" :error-messages="form.errors.last_name" />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field
                                v-model="form.job_title"
                                label="Ruolo"
                                placeholder="Es. Responsabile acquisti"
                                :error-messages="form.errors.job_title"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="form.location_id"
                                :items="locationItems"
                                label="Sede"
                                clearable
                                :no-data-text="'Nessuna sede'"
                                :error-messages="form.errors.location_id"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="form.email"
                                label="Email"
                                type="email"
                                prepend-inner-icon="mdi-email-outline"
                                :error-messages="form.errors.email"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field
                                v-model="form.phone"
                                label="Telefono"
                                type="tel"
                                prepend-inner-icon="mdi-phone-outline"
                                :error-messages="form.errors.phone"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field
                                v-model="form.mobile"
                                label="Cellulare"
                                type="tel"
                                prepend-inner-icon="mdi-cellphone"
                                :error-messages="form.errors.mobile"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea v-model="form.notes" label="Note" rows="2" auto-grow :error-messages="form.errors.notes" />
                        </v-col>
                        <v-col cols="12">
                            <v-switch
                                v-model="form.is_primary"
                                label="Referente principale"
                                :disabled="contact?.is_primary"
                                :messages="contact?.is_primary ? 'Per cambiarlo, imposta come principale un altro contatto.' : ''"
                            />
                        </v-col>
                    </v-row>
                </v-form>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn type="submit" form="contact-form" color="primary" variant="flat" :loading="form.processing">
                    {{ isNew ? 'Aggiungi contatto' : 'Salva' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
