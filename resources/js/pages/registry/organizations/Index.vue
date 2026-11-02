<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps({
    organizations: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    archivedCount: { type: Number, default: 0 },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const loading = ref(false);
const query = reactive({
    search: props.filters.search,
    type: props.filters.type,
    role: props.filters.role,
});

const roles = [
    { value: 'customer', title: 'Clienti' },
    { value: 'supplier', title: 'Fornitori' },
];

const headers = [
    { title: 'Organizzazione', key: 'name', sortable: true },
    { title: 'Tipo', key: 'type', sortable: false },
    { title: 'Sede principale', key: 'primary_location', sortable: false },
    { title: 'Referente', key: 'primary_contact', sortable: false },
    { title: 'Sedi / Contatti', key: 'counts', sortable: false, align: 'center' },
    { title: '', key: 'actions', sortable: false, align: 'end' },
];

function load(params = {}) {
    router.get(
        route('registry.organizations.index'),
        {
            ...query,
            status: props.filters.status,
            sort_by: props.filters.sort_by,
            sort_dir: props.filters.sort_dir,
            per_page: props.filters.per_page,
            ...params,
        },
        {
            only: ['organizations', 'filters', 'archivedCount'],
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
    const sortKey = sort?.key ?? 'name';
    const sortDir = sort?.order ?? 'asc';

    if (
        page !== props.organizations.meta.current_page ||
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
    () => [query.type, query.role],
    () => load({ page: 1 }),
);

function open(event, { item }) {
    router.visit(route('registry.organizations.show', item.id));
}
</script>

<template>
    <div>
        <PageHeader
            title="Organizzazioni"
            :subtitle="`${organizations.meta.total} ${filters.status === 'archived' ? 'archiviate' : 'in anagrafica'}`"
        >
            <template v-if="can('organizations.create')" #actions>
                <v-btn color="primary" prepend-icon="mdi-domain-plus" v-bind="link(route('registry.organizations.create'))">
                    Nuova organizzazione
                </v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-tabs :model-value="filters.status" color="primary" @update:model-value="(status) => load({ status, page: 1 })">
                <v-tab value="active">Attive</v-tab>
                <v-tab value="archived">
                    Archiviate
                    <v-badge v-if="archivedCount" :content="archivedCount" color="grey" inline />
                </v-tab>
            </v-tabs>
            <v-divider />

            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="8">
                        <v-text-field
                            v-model="query.search"
                            label="Cerca per nome, partita IVA, codice fiscale, email o città"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            hide-details
                            @click:clear="query.search = ''"
                        />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select v-model="query.role" :items="roles" label="Ruolo" clearable hide-details />
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select v-model="query.type" :items="types" label="Tipo" clearable hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table-server
                :headers="headers"
                :items="organizations.data"
                :items-length="organizations.meta.total"
                :page="organizations.meta.current_page"
                :items-per-page="filters.per_page"
                :items-per-page-options="[10, 25, 50, 100]"
                :sort-by="[{ key: filters.sort_by, order: filters.sort_dir }]"
                :loading="loading"
                must-sort
                hover
                class="cursor-pointer"
                @update:options="onOptions"
                @click:row="open"
            >
                <template #[`item.name`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.name }}</div>
                        <div v-if="item.vat_number" class="text-caption text-medium-emphasis">P.IVA {{ item.vat_number }}</div>
                    </div>
                </template>

                <template #[`item.type`]="{ item }">
                    <div class="d-flex flex-wrap ga-1 py-1">
                        <v-chip variant="tonal">{{ item.type_label }}</v-chip>
                        <v-chip v-if="item.is_supplier" variant="tonal" color="secondary" prepend-icon="mdi-truck-outline">Fornitore</v-chip>
                    </div>
                </template>

                <template #[`item.primary_location`]="{ item }">
                    <span v-if="item.primary_location">
                        {{ item.primary_location.city }}
                        <template v-if="item.primary_location.province">({{ item.primary_location.province }})</template>
                    </span>
                    <span v-else class="text-medium-emphasis">—</span>
                </template>

                <template #[`item.primary_contact`]="{ item }">
                    <div v-if="item.primary_contact">
                        <div>{{ item.primary_contact.full_name }}</div>
                        <div v-if="item.primary_contact.email" class="text-caption text-medium-emphasis">{{ item.primary_contact.email }}</div>
                    </div>
                    <span v-else class="text-medium-emphasis">—</span>
                </template>

                <template #[`item.counts`]="{ item }">
                    <span class="text-no-wrap">
                        <v-icon icon="mdi-map-marker-outline" size="small" />
                        {{ item.locations_count }}
                        <v-icon icon="mdi-account-outline" size="small" class="ml-2" />
                        {{ item.contacts_count }}
                    </span>
                </template>

                <template #[`item.actions`]="{ item }">
                    <v-btn
                        v-if="item.can?.update"
                        icon="mdi-pencil-outline"
                        variant="text"
                        size="small"
                        title="Modifica"
                        v-bind="link(route('registry.organizations.edit', item.id))"
                        @click.stop
                    />
                    <v-icon v-else icon="mdi-chevron-right" />
                </template>

                <template #no-data>
                    <v-empty-state
                        icon="mdi-domain"
                        :title="
                            filters.search || filters.type
                                ? 'Nessuna organizzazione trovata'
                                : filters.status === 'archived'
                                  ? 'Nessuna organizzazione archiviata'
                                  : 'Nessuna organizzazione'
                        "
                        :text="filters.search || filters.type ? 'Prova a modificare i filtri.' : ''"
                        size="56"
                    />
                </template>
            </v-data-table-server>
        </v-card>
    </div>
</template>
