<script setup>
import { computed } from 'vue';

/**
 * Shared fields for the create / edit user pages.
 * `v-model:form` is an Inertia useForm() instance owned by the page.
 */
const form = defineModel('form', { type: Object, required: true });

const props = defineProps({
    roles: { type: Array, required: true },
    isNew: { type: Boolean, default: false },
    isSelf: { type: Boolean, default: false },
});

const needsPassword = computed(() => !props.isNew || !form.value.send_reset_link);
</script>

<template>
    <v-row>
        <v-col cols="12" md="6">
            <v-text-field v-model="form.name" label="Nome e cognome" :error-messages="form.errors.name" autofocus />
        </v-col>
        <v-col cols="12" md="6">
            <v-text-field v-model="form.email" label="Email" type="email" :error-messages="form.errors.email" />
        </v-col>

        <v-col cols="12">
            <v-autocomplete
                v-model="form.roles"
                :items="roles"
                label="Ruoli"
                multiple
                chips
                closable-chips
                :error-messages="form.errors.roles || form.errors['roles.0']"
                hint="I permessi dell'utente derivano dai ruoli assegnati."
                persistent-hint
            />
        </v-col>

        <v-col cols="12">
            <v-switch
                v-model="form.is_active"
                label="Account attivo"
                :disabled="isSelf"
                :messages="
                    isSelf ? 'Non puoi disattivare il tuo account.' : form.is_active ? 'L\'utente può accedere.' : 'L\'utente non può accedere.'
                "
                :error-messages="form.errors.is_active"
            />
        </v-col>

        <v-col cols="12">
            <v-divider class="mb-4" />
            <div class="text-subtitle-1 font-weight-medium mb-2">Password</div>

            <v-checkbox v-if="isNew" v-model="form.send_reset_link" label="Invia all'utente un'email per scegliere la password" class="mb-2" />

            <v-row v-if="needsPassword">
                <v-col cols="12" md="6">
                    <v-text-field
                        v-model="form.password"
                        :label="isNew ? 'Password' : 'Nuova password'"
                        type="password"
                        autocomplete="new-password"
                        :hint="isNew ? '' : 'Lascia vuoto per non modificarla.'"
                        persistent-hint
                        :error-messages="form.errors.password"
                    />
                </v-col>
                <v-col cols="12" md="6">
                    <v-text-field
                        v-model="form.password_confirmation"
                        label="Conferma password"
                        type="password"
                        autocomplete="new-password"
                        :error-messages="form.errors.password_confirmation"
                    />
                </v-col>
            </v-row>
        </v-col>
    </v-row>
</template>
