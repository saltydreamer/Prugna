<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';
import { formatHours } from '@/utils/duration';

const props = defineProps({
    contracts: { type: Object, required: true },
    filters: { type: Object, required: true },
    customers: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const loading = ref(false);
const query = reactive({ search: props.filters.search, customer: props.filters.customer });

const headers = [
    { title: 'Contratto', key: 'number', sortable: false },
    { title: 'Cliente', key: 'organization', sortable: false },
    { title: 'Validità', key: 'starts_on', sortable: false },
    { title: 'Ore', key: 'usage', sortable: false, width: 220 },
    { title: 'Stato', key: 'status', sortable: false },
];

function load(params = {}) {
    router.get(
        route('interventions.contracts.index'),
        { search: query.search || undefined, customer: query.customer ?? undefined, status: props.filters.status, ...params },
        { preserveState: true, replace: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) },
    );
}

let timeout;
watch(
    () => query.search,
    () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => load(), 350);
    },
);
watch(
    () => query.customer,
    () => load(),
);

const percent = (item) => (item.total_minutes ? Math.min(100, Math.round((item.used_minutes / item.total_minutes) * 100)) : 0);
</script>

<template>
    <div>
        <PageHeader
            title="Contratti a monte ore"
            subtitle="Pacchetti di ore acquistati dai clienti: gli interventi coperti scalano le ore invece di andare in fattura"
        >
            <template v-if="can('contracts.manage')" #actions>
                <v-btn color="primary" prepend-icon="mdi-plus" v-bind="link(route('interventions.contracts.create'))">Nuovo contratto</v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-tabs :model-value="filters.status" color="primary" @update:model-value="(status) => load({ status })">
                <v-tab value="active">Attivi</v-tab>
                <v-tab value="closed">Chiusi</v-tab>
                <v-tab value="all">Tutti</v-tab>
            </v-tabs>
            <v-divider />
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="6">
                        <v-text-field v-model="query.search" label="Cerca" prepend-inner-icon="mdi-magnify" clearable hide-details />
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-autocomplete v-model="query.customer" :items="customers" label="Cliente" clearable hide-details />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-data-table
                :headers="headers"
                :items="contracts.data"
                :loading="loading"
                :items-per-page="-1"
                hide-default-footer
                hover
                class="cursor-pointer"
                @click:row="(event, { item }) => router.visit(route('interventions.contracts.show', item.id))"
            >
                <template #[`item.number`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.title }}</div>
                        <div class="text-caption text-medium-emphasis">{{ item.number }} · {{ formatMoney(item.price) }}</div>
                    </div>
                </template>
                <template #[`item.organization`]="{ item }">{{ item.organization?.name }}</template>
                <template #[`item.starts_on`]="{ item }">
                    <span class="text-no-wrap">
                        {{ formatDate(item.starts_on) }} → {{ item.ends_on ? formatDate(item.ends_on) : 'senza scadenza' }}
                    </span>
                </template>
                <template #[`item.usage`]="{ item }">
                    <div class="d-flex justify-space-between text-caption">
                        <span>{{ formatHours(item.used_minutes) }} usate</span>
                        <span>residuo {{ formatHours(item.remaining_minutes) }}</span>
                    </div>
                    <v-progress-linear
                        :model-value="percent(item)"
                        :color="percent(item) >= 100 ? 'error' : percent(item) >= 80 ? 'warning' : 'primary'"
                        rounded
                        height="6"
                    />
                </template>
                <template #[`item.status`]="{ item }">
                    <v-chip size="small" :color="item.status_color" variant="tonal">{{ item.status_label }}</v-chip>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-timer-sand"
                        title="Nessun contratto"
                        text="Crea un pacchetto di ore per un cliente: gli interventi lo useranno automaticamente."
                        size="48"
                    />
                </template>
            </v-data-table>
        </v-card>
    </div>
</template>
