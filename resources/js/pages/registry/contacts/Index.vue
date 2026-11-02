<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    contacts: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const { link } = useInertiaLink();

const loading = ref(false);
const query = reactive({
    search: props.filters.search,
    primary: props.filters.primary,
    role: props.filters.role ?? null,
});

const headers = [
    { title: 'Nome', key: 'last_name', sortable: true },
    { title: 'Azienda', key: 'organization', sortable: false },
    { title: 'Ruolo', key: 'job_title', sortable: false },
    { title: 'Email', key: 'email', sortable: false },
    { title: 'Telefono', key: 'phone', sortable: false },
    { title: 'Sede', key: 'location', sortable: false },
];

function load(params = {}) {
    router.get(
        route('registry.contacts.index'),
        {
            search: query.search,
            primary: query.primary ? 1 : undefined,
            role: query.role ?? undefined,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['contacts', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
    );
}

function onOptions({ page, itemsPerPage, sortBy }) {
    const [sort] = sortBy;
    const sortKey = sort?.key ?? 'last_name';
    const sortDir = sort?.order ?? 'asc';

    if (
        page !== props.contacts.meta.current_page ||
        itemsPerPage !== props.filters.per_page ||
        sortKey !== props.filters.sort_by ||
        sortDir !== props.filters.sort_dir
    ) {
        load({ page, per_page: itemsPerPage, sort_by: sortKey, sort_dir: sortDir });
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
    () => [query.primary, query.role],
    () => load({ page: 1 }),
);
</script>

<template>
    <div>
        <PageHeader title="Contatti" :subtitle="`Rubrica delle persone di riferimento · ${contacts.meta.total} contatti`" />

        <v-card>
            <v-card-text>
                <v-row dense align="center">
                    <v-col cols="12" md="6">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per nome, email, telefono o azienda"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-select
                            v-model="query.role"
                            :items="[
                                { value: 'customer', title: 'Clienti' },
                                { value: 'supplier', title: 'Fornitori' },
                            ]"
                            label="Di"
                            clearable
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="3">
                        <v-switch v-model="query.primary" label="Solo referenti principali" hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="contacts.data"
                :items-length="contacts.meta.total"
                :page="contacts.meta.current_page"
                :items-per-page="filters.per_page"
                :items-per-page-options="[10, 25, 50, 100]"
                :sort-by="[{ key: filters.sort_by, order: filters.sort_dir }]"
                :loading="loading"
                must-sort
                hover
                @update:options="onOptions"
            >
                <template #[`item.last_name`]="{ item }">
                    <div class="d-flex align-center ga-2 py-2">
                        <v-icon v-if="item.is_primary" icon="mdi-star" color="primary" size="small" title="Referente principale" />
                        <span class="font-weight-medium">{{ item.last_name }} {{ item.first_name }}</span>
                    </div>
                </template>
                <template #[`item.organization`]="{ item }">
                    <a v-bind="link(route('registry.organizations.show', { organization: item.organization.id, tab: 'contacts' }))">
                        {{ item.organization.name }}
                    </a>
                    <div class="text-caption text-medium-emphasis">{{ item.organization.role === 'supplier' ? 'Fornitore' : 'Cliente' }}</div>
                </template>
                <template #[`item.job_title`]="{ item }">{{ item.job_title || '—' }}</template>
                <template #[`item.email`]="{ item }">
                    <a v-if="item.email" :href="`mailto:${item.email}`">{{ item.email }}</a>
                    <span v-else>—</span>
                </template>
                <template #[`item.phone`]="{ item }">
                    <div v-if="item.phone">{{ item.phone }}</div>
                    <div v-if="item.mobile">
                        <v-icon icon="mdi-cellphone" size="x-small" />
                        {{ item.mobile }}
                    </div>
                    <span v-if="!item.phone && !item.mobile">—</span>
                </template>
                <template #[`item.location`]="{ item }">{{ item.location ? `${item.location.name} · ${item.location.city}` : '—' }}</template>
                <template #no-data>
                    <v-empty-state icon="mdi-account-search-outline" title="Nessun contatto trovato" size="56" />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
