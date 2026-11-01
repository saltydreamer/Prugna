<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, relativeTime } from '@/utils/format';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    roles: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const { confirm } = useConfirm();

const loading = ref(false);

const query = reactive({
    search: props.filters.search,
    role: props.filters.role,
    status: props.filters.status,
});

const headers = [
    { title: 'Utente', key: 'name', sortable: true },
    { title: 'Ruoli', key: 'roles', sortable: false },
    { title: 'Stato', key: 'is_active', sortable: false },
    { title: 'Ultimo accesso', key: 'last_login_at', sortable: true },
    { title: 'Creato il', key: 'created_at', sortable: true },
    { title: '', key: 'actions', sortable: false, align: 'end' },
];

const statusOptions = [
    { value: 'active', title: 'Attivi' },
    { value: 'inactive', title: 'Disattivati' },
];

const roleTitle = (name) => props.roles.find((role) => role.value === name)?.title ?? name;

function load(params = {}) {
    router.get(
        route('admin.users.index'),
        {
            ...query,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['users', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}

// Vuetify emits the table options (page, items per page, sort) on change.
function onOptions({ page, itemsPerPage, sortBy }) {
    const [sort] = sortBy;
    const changed =
        page !== props.users.meta.current_page ||
        itemsPerPage !== props.filters.per_page ||
        (sort?.key ?? 'created_at') !== props.filters.sort_by ||
        (sort?.order ?? 'desc') !== props.filters.sort_dir;

    if (changed) {
        load({ page, per_page: itemsPerPage, sort_by: sort?.key ?? 'created_at', sort_dir: sort?.order ?? 'desc' });
    }
}

let searchTimeout;
watch(
    () => query.search,
    () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => load({ page: 1 }), 350);
    },
);
watch(
    () => [query.role, query.status],
    () => load({ page: 1 }),
);

async function destroy(user) {
    const confirmed = await confirm({
        title: `Eliminare ${user.name}?`,
        message: "L'account e tutti i suoi dati verranno eliminati definitivamente.",
        confirmText: 'Elimina',
        color: 'error',
        icon: 'mdi-delete-outline',
    });

    if (confirmed) {
        router.delete(route('admin.users.destroy', user.id), { preserveScroll: true });
    }
}
</script>

<template>
    <PageHeader title="Utenti" :subtitle="`${users.meta.total} utenti registrati`">
        <template v-if="can('users.create')" #actions>
            <v-btn color="primary" prepend-icon="mdi-account-plus-outline" v-bind="link(route('admin.users.create'))">Nuovo utente</v-btn>
        </template>
    </PageHeader>

    <v-card>
        <v-card-text>
            <v-row dense>
                <v-col cols="12" md="6">
                    <v-text-field
                        v-model="query.search"
                        label="Cerca per nome o email"
                        prepend-inner-icon="mdi-magnify"
                        clearable
                        hide-details
                        @click:clear="query.search = ''"
                    />
                </v-col>
                <v-col cols="6" md="3">
                    <v-select v-model="query.role" :items="roles" label="Ruolo" clearable hide-details />
                </v-col>
                <v-col cols="6" md="3">
                    <v-select v-model="query.status" :items="statusOptions" label="Stato" clearable hide-details />
                </v-col>
            </v-row>
        </v-card-text>

        <v-data-table-server
            :headers="headers"
            :items="users.data"
            :items-length="users.meta.total"
            :page="users.meta.current_page"
            :items-per-page="filters.per_page"
            :items-per-page-options="[10, 25, 50, 100]"
            :sort-by="[{ key: filters.sort_by, order: filters.sort_dir }]"
            :loading="loading"
            must-sort
            hover
            @update:options="onOptions"
        >
            <template #[`item.name`]="{ item }">
                <div class="d-flex align-center ga-3 py-2">
                    <UserAvatar :user="item" size="36" />
                    <div>
                        <div class="font-weight-medium">{{ item.name }}</div>
                        <div class="text-caption text-medium-emphasis">{{ item.email }}</div>
                    </div>
                </div>
            </template>

            <template #[`item.roles`]="{ item }">
                <div class="d-flex flex-wrap ga-1">
                    <v-chip v-for="role in item.roles" :key="role" color="secondary" variant="tonal">{{ roleTitle(role) }}</v-chip>
                </div>
            </template>

            <template #[`item.is_active`]="{ item }">
                <v-chip :color="item.is_active ? 'success' : 'error'" variant="tonal" :prepend-icon="item.is_active ? 'mdi-check' : 'mdi-cancel'">
                    {{ item.is_active ? 'Attivo' : 'Disattivato' }}
                </v-chip>
                <v-icon
                    v-if="!item.email_verified_at"
                    icon="mdi-email-alert-outline"
                    color="warning"
                    size="small"
                    class="ml-1"
                    title="Email non verificata"
                />
            </template>

            <template #[`item.last_login_at`]="{ item }">
                <span :title="item.last_login_at">{{ item.last_login_at ? relativeTime(item.last_login_at) : 'Mai' }}</span>
            </template>

            <template #[`item.created_at`]="{ item }">{{ formatDate(item.created_at) }}</template>

            <template #[`item.actions`]="{ item }">
                <div class="d-flex justify-end">
                    <v-btn
                        v-if="item.can?.update"
                        icon="mdi-pencil-outline"
                        variant="text"
                        size="small"
                        title="Modifica"
                        v-bind="link(route('admin.users.edit', item.id))"
                    />
                    <v-btn
                        v-if="item.can?.delete"
                        icon="mdi-delete-outline"
                        variant="text"
                        size="small"
                        color="error"
                        title="Elimina"
                        @click="destroy(item)"
                    />
                </div>
            </template>

            <template #no-data>
                <v-empty-state icon="mdi-account-search-outline" title="Nessun utente trovato" text="Prova a modificare i filtri." size="56" />
            </template>
        </v-data-table-server>
    </v-card>
</template>
