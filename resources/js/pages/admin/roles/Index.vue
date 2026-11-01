<script setup>
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';

defineProps({
    roles: { type: Array, required: true },
    totalPermissions: { type: Number, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const { confirm } = useConfirm();

async function destroy(role) {
    const confirmed = await confirm({
        title: `Eliminare il ruolo "${role.label}"?`,
        message: role.users_count ? `${role.users_count} utenti perderanno i permessi concessi da questo ruolo.` : '',
        confirmText: 'Elimina',
        color: 'error',
        icon: 'mdi-delete-outline',
    });

    if (confirmed) {
        router.delete(route('admin.roles.destroy', role.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader title="Ruoli e permessi" subtitle="I ruoli raggruppano i permessi; gli utenti ricevono i permessi dei ruoli assegnati.">
        <template v-if="can('roles.create')" #actions>
            <v-btn color="primary" prepend-icon="mdi-plus" v-bind="link(route('admin.roles.create'))">Nuovo ruolo</v-btn>
        </template>
    </PageHeader>

    <v-row>
        <v-col v-for="role in roles" :key="role.id" cols="12" md="6" lg="4">
            <v-card class="h-100 d-flex flex-column">
                <v-card-item>
                    <template #prepend>
                        <v-avatar :color="role.is_super_admin ? 'error' : role.is_system ? 'primary' : 'secondary'" variant="tonal">
                            <v-icon :icon="role.is_super_admin ? 'mdi-shield-crown-outline' : 'mdi-shield-account-outline'" />
                        </v-avatar>
                    </template>
                    <v-card-title>{{ role.label }}</v-card-title>
                    <v-card-subtitle>
                        <code>{{ role.name }}</code>
                    </v-card-subtitle>
                    <template #append>
                        <v-chip v-if="role.is_system" variant="outlined">Sistema</v-chip>
                    </template>
                </v-card-item>

                <v-card-text class="flex-grow-1">
                    <div v-if="role.is_super_admin" class="text-body-2 mb-3">Ha automaticamente tutti i permessi dell'applicazione.</div>
                    <div class="text-caption text-medium-emphasis mb-1">Permessi: {{ role.permissions_count }} / {{ totalPermissions }}</div>
                    <v-progress-linear :model-value="(role.permissions_count / totalPermissions) * 100" color="primary" rounded height="6" />
                    <div class="d-flex align-center mt-3 text-body-2">
                        <v-icon icon="mdi-account-multiple-outline" size="small" class="mr-1" />
                        {{ role.users_count }} {{ role.users_count === 1 ? 'utente' : 'utenti' }}
                    </div>
                </v-card-text>

                <v-card-actions v-if="role.can.update || role.can.delete">
                    <v-btn v-if="role.can.update" variant="text" prepend-icon="mdi-pencil-outline" v-bind="link(route('admin.roles.edit', role.id))">
                        Modifica
                    </v-btn>
                    <v-spacer />
                    <v-btn v-if="role.can.delete" variant="text" color="error" icon="mdi-delete-outline" title="Elimina" @click="destroy(role)" />
                </v-card-actions>
            </v-card>
        </v-col>
    </v-row>
</template>
