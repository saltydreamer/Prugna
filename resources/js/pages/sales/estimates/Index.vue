<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatMoney, formatPercent } from '@/utils/format';

const props = defineProps({
    estimates: { type: Object, required: true },
    search: { type: String, default: '' },
});

const { link } = useInertiaLink();
const query = ref(props.search);

const headers = [
    { title: 'Proposta', key: 'title', sortable: false },
    { title: 'Cliente', key: 'organization', sortable: false },
    { title: 'Canone', key: 'monthly', sortable: false, align: 'end' },
    { title: 'Totale', key: 'price', sortable: false, align: 'end' },
    { title: 'Margine', key: 'margin_percent', sortable: false, align: 'end' },
    { title: 'Stato', key: 'status', sortable: false },
];

let timeout;
watch(query, () => {
    clearTimeout(timeout);
    timeout = setTimeout(
        () => router.get(route('sales.estimates.index'), { search: query.value || undefined }, { preserveState: true, replace: true }),
        350,
    );
});
</script>

<template>
    <div>
        <PageHeader title="Preventivatore" subtitle="Sperimentale: soluzioni a costo + ricarico, proposte al cliente come canone mensile">
            <template #actions>
                <v-btn color="primary" prepend-icon="mdi-plus" v-bind="link(route('sales.estimates.create'))">Nuova proposta</v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-card-text>
                <v-text-field v-model="query" label="Cerca" prepend-inner-icon="mdi-magnify" clearable hide-details />
            </v-card-text>
            <v-data-table
                :headers="headers"
                :items="estimates.data"
                :items-per-page="-1"
                hide-default-footer
                hover
                class="cursor-pointer"
                @click:row="(event, { item }) => router.visit(route('sales.estimates.show', item.id))"
            >
                <template #[`item.title`]="{ item }">
                    <div class="py-2">
                        <div class="font-weight-medium">{{ item.title }}</div>
                        <div class="text-caption text-medium-emphasis">{{ item.number }} · {{ item.months }} mesi</div>
                    </div>
                </template>
                <template #[`item.organization`]="{ item }">{{ item.organization ?? '—' }}</template>
                <template #[`item.monthly`]="{ item }">
                    <span class="text-no-wrap">{{ formatMoney(item.monthly) }}</span>
                    <div class="text-caption text-medium-emphasis">al mese</div>
                </template>
                <template #[`item.price`]="{ item }">
                    <span class="text-no-wrap">{{ formatMoney(item.price) }}</span>
                </template>
                <template #[`item.margin_percent`]="{ item }">{{ formatPercent(item.margin_percent) }}</template>
                <template #[`item.status`]="{ item }">
                    <v-chip size="small" variant="tonal" :color="item.status === 'accepted' ? 'success' : 'grey'">
                        {{ item.status === 'accepted' ? 'Accettata' : 'Bozza' }}
                    </v-chip>
                </template>
                <template #no-data>
                    <v-empty-state
                        icon="mdi-calculator-variant-outline"
                        title="Nessuna proposta"
                        text="Inserisci gli articoli con il loro costo (a mano o dal link del negozio), scegli il ricarico: il canone mensile e il report per il cliente si calcolano da soli."
                        size="48"
                    />
                </template>
            </v-data-table>
        </v-card>
    </div>
</template>
