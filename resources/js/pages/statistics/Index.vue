<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import ColumnChart from '@/components/charts/ColumnChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatMoney, formatPercent } from '@/utils/format';

const props = defineProps({
    /** [{ year, revenue, invoices, customers }] ascending. */
    years: { type: Array, required: true },
    year: { type: Number, default: null },
    /** { current: number[12], previous: number[12] } */
    months: { type: Object, default: null },
    /** { current, previous, until } (until: same period of a year in progress). */
    comparison: { type: Object, default: null },
    /** Customers of the selected year: [{ id, name, revenue, invoices, previous, share }] */
    customers: { type: Array, default: () => [] },
    /** Every customer in every year: [{ id, name, years: { 2025: n }, total }] */
    customersByYear: { type: Array, default: () => [] },
    flatRate: { type: Boolean, default: false },
});

const { link } = useInertiaLink();

const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const euroRounded = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
// Whole euros from a thousand up, cents below (so small amounts match the charts and tables).
const euro = { format: (value) => (Math.abs(value) >= 1000 ? euroRounded.format(value) : formatMoney(Math.round(value * 100) / 100)) };
const compact = (value) =>
    Math.abs(value) >= 1000 ? `${new Intl.NumberFormat('it-IT', { maximumFractionDigits: 1 }).format(value / 1000)}k` : String(value);

const selected = computed(() => props.years.find((item) => item.year === props.year) ?? null);
const yearIndex = computed(() => props.years.findIndex((item) => item.year === props.year));

function selectYear(year) {
    router.get(route('statistics.index'), { year }, { preserveScroll: true, preserveState: true });
}

const delta = computed(() => {
    if (!props.comparison || !props.comparison.previous) {
        return null;
    }

    return ((props.comparison.current - props.comparison.previous) / Math.abs(props.comparison.previous)) * 100;
});

const comparisonLabel = computed(() =>
    props.comparison?.until
        ? `Allo stesso periodo del ${props.year - 1} (al ${formatDate(props.comparison.until, { day: 'numeric', month: 'short' })})`
        : `Rispetto al ${props.year - 1}`,
);

const average = computed(() => (selected.value?.invoices ? selected.value.revenue / selected.value.invoices : 0));

// Charts and their table views.
const yearsTable = ref(false);
const monthsTable = ref(false);

const monthsSeries = computed(() => [
    { name: String(props.year - 1), values: props.months?.previous ?? [], tone: 'muted' },
    { name: String(props.year), values: props.months?.current ?? [], tone: 'accent' },
]);

// Customers.
const tab = ref('year');
const search = ref('');
const maxRevenue = computed(() => Math.max(1, ...props.customers.map((item) => item.revenue)));

function change(item) {
    return item.previous ? ((item.revenue - item.previous) / Math.abs(item.previous)) * 100 : null;
}

const customerHeaders = computed(() => [
    { title: 'Cliente', key: 'name' },
    { title: 'Fatturato', key: 'revenue', align: 'end' },
    { title: 'Quota', key: 'share', align: 'end' },
    { title: 'Fatture', key: 'invoices', align: 'end' },
    { title: String(props.year - 1), key: 'previous', align: 'end' },
    { title: 'Variazione', key: 'change', align: 'end', value: (item) => change(item) ?? -Infinity },
]);

const yearColumns = computed(() => props.years.map((item) => item.year));
const pivotHeaders = computed(() => [
    { title: 'Cliente', key: 'name' },
    ...yearColumns.value.map((year) => ({ title: String(year), key: `y${year}`, align: 'end', value: (item) => item.years[year] ?? 0 })),
    { title: 'Totale', key: 'total', align: 'end' },
]);
</script>

<template>
    <div>
        <PageHeader
            title="Statistiche"
            subtitle="Fatturato dalle fatture emesse: al netto delle note di credito, IVA e rimborso del bollo esclusi."
        />

        <v-card v-if="!years.length" class="text-center py-12">
            <v-icon icon="mdi-chart-bar" size="40" class="text-medium-emphasis mb-2" />
            <div class="text-body-1">Nessuna fattura emessa</div>
            <div class="text-body-2 text-medium-emphasis">Le statistiche compaiono dopo la prima fattura.</div>
        </v-card>

        <template v-else>
            <!-- Filter: one row above everything it scopes. -->
            <div class="d-flex flex-wrap align-center ga-2 mb-4">
                <span class="text-body-2 text-medium-emphasis mr-1">Anno</span>
                <v-chip-group :model-value="year" mandatory selected-class="text-primary" @update:model-value="selectYear">
                    <v-chip v-for="item in [...years].reverse()" :key="item.year" :value="item.year" variant="outlined" size="small">
                        {{ item.year }}
                    </v-chip>
                </v-chip-group>
            </div>

            <v-row class="mb-2">
                <v-col cols="12" sm="6" lg="3">
                    <v-card class="h-100">
                        <v-card-item>
                            <v-card-subtitle>Fatturato {{ year }}</v-card-subtitle>
                            <div class="stat-value">{{ euro.format(selected?.revenue ?? 0) }}</div>
                        </v-card-item>
                    </v-card>
                </v-col>
                <v-col cols="12" sm="6" lg="3">
                    <v-card class="h-100">
                        <v-card-item>
                            <v-card-subtitle class="text-wrap">{{ comparisonLabel }}</v-card-subtitle>
                            <div class="d-flex align-center ga-1 stat-value">
                                <template v-if="delta !== null">
                                    <v-icon
                                        :icon="delta >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'"
                                        :color="delta >= 0 ? 'success' : 'error'"
                                        size="20"
                                    />
                                    {{ delta >= 0 ? '+' : '' }}{{ formatPercent(delta) }}
                                </template>
                                <template v-else>—</template>
                            </div>
                            <div class="text-caption text-medium-emphasis">
                                {{ euro.format(comparison?.current ?? 0) }} contro {{ euro.format(comparison?.previous ?? 0) }}
                            </div>
                        </v-card-item>
                    </v-card>
                </v-col>
                <v-col cols="6" lg="3">
                    <v-card class="h-100">
                        <v-card-item>
                            <v-card-subtitle>Fatture emesse</v-card-subtitle>
                            <div class="stat-value">{{ selected?.invoices ?? 0 }}</div>
                            <div class="text-caption text-medium-emphasis">media {{ euro.format(average) }}</div>
                        </v-card-item>
                    </v-card>
                </v-col>
                <v-col cols="6" lg="3">
                    <v-card class="h-100">
                        <v-card-item>
                            <v-card-subtitle>Clienti fatturati</v-card-subtitle>
                            <div class="stat-value">{{ selected?.customers ?? 0 }}</div>
                        </v-card-item>
                    </v-card>
                </v-col>
            </v-row>

            <v-alert v-if="flatRate" type="info" variant="tonal" density="compact" class="mb-6" icon="mdi-information-outline">
                Regime forfettario: la soglia dei ricavi si verifica sugli
                <strong>incassi</strong>
                dell'anno; qui il fatturato è per data della fattura.
            </v-alert>

            <v-row>
                <v-col cols="12" md="5">
                    <v-card class="h-100">
                        <v-card-item>
                            <v-card-title>Fatturato per anno</v-card-title>
                            <v-card-subtitle>Clicca un anno per vederne il dettaglio</v-card-subtitle>
                            <template #append>
                                <v-btn
                                    size="small"
                                    variant="text"
                                    :prepend-icon="yearsTable ? 'mdi-chart-bar' : 'mdi-table'"
                                    @click="yearsTable = !yearsTable"
                                >
                                    {{ yearsTable ? 'Grafico' : 'Tabella' }}
                                </v-btn>
                            </template>
                        </v-card-item>
                        <v-card-text>
                            <ColumnChart
                                v-if="!yearsTable"
                                label="Fatturato per anno"
                                :categories="years.map((item) => String(item.year))"
                                :series="[{ name: 'Fatturato', values: years.map((item) => item.revenue) }]"
                                :highlight="yearIndex"
                                :format="formatMoney"
                                :format-tick="compact"
                                selectable
                                @select="(index) => selectYear(years[index].year)"
                            />
                            <v-table v-else density="compact">
                                <thead>
                                    <tr>
                                        <th>Anno</th>
                                        <th class="text-end">Fatturato</th>
                                        <th class="text-end">Fatture</th>
                                        <th class="text-end">Clienti</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in [...years].reverse()" :key="item.year">
                                        <td>{{ item.year }}</td>
                                        <td class="text-end tabular">{{ formatMoney(item.revenue) }}</td>
                                        <td class="text-end tabular">{{ item.invoices }}</td>
                                        <td class="text-end tabular">{{ item.customers }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="7">
                    <v-card class="h-100">
                        <v-card-item>
                            <v-card-title>Mese per mese</v-card-title>
                            <v-card-subtitle>{{ year }} a confronto con il {{ year - 1 }}</v-card-subtitle>
                            <template #append>
                                <v-btn
                                    size="small"
                                    variant="text"
                                    :prepend-icon="monthsTable ? 'mdi-chart-bar' : 'mdi-table'"
                                    @click="monthsTable = !monthsTable"
                                >
                                    {{ monthsTable ? 'Grafico' : 'Tabella' }}
                                </v-btn>
                            </template>
                        </v-card-item>
                        <v-card-text>
                            <ColumnChart
                                v-if="!monthsTable"
                                :label="`Fatturato mensile ${year} e ${year - 1}`"
                                :categories="MONTHS"
                                :series="monthsSeries"
                                :format="formatMoney"
                                :format-tick="compact"
                            />
                            <v-table v-else density="compact">
                                <thead>
                                    <tr>
                                        <th>Mese</th>
                                        <th class="text-end">{{ year }}</th>
                                        <th class="text-end">{{ year - 1 }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(month, index) in MONTHS" :key="month">
                                        <td>{{ month }}</td>
                                        <td class="text-end tabular">{{ formatMoney(months?.current[index] ?? 0) }}</td>
                                        <td class="text-end tabular">{{ formatMoney(months?.previous[index] ?? 0) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-card class="mt-6">
                <v-card-item>
                    <v-card-title>Clienti</v-card-title>
                    <template #append>
                        <v-text-field
                            v-model="search"
                            placeholder="Cerca cliente"
                            prepend-inner-icon="mdi-magnify"
                            hide-details
                            clearable
                            style="width: 240px"
                        />
                    </template>
                </v-card-item>
                <v-tabs v-model="tab" color="primary" class="px-2">
                    <v-tab value="year">Anno {{ year }}</v-tab>
                    <v-tab value="all">Tutti gli anni</v-tab>
                </v-tabs>
                <v-divider />

                <v-data-table
                    v-if="tab === 'year'"
                    :headers="customerHeaders"
                    :items="customers"
                    :search="search"
                    :items-per-page="25"
                    :sort-by="[{ key: 'revenue', order: 'desc' }]"
                    density="compact"
                    no-data-text="Nessun cliente fatturato nell'anno"
                >
                    <template #[`item.name`]="{ item }">
                        <a v-bind="link(route('registry.organizations.show', item.id))" class="text-decoration-none">{{ item.name }}</a>
                    </template>
                    <template #[`item.revenue`]="{ item }">
                        <div class="d-flex align-center justify-end ga-3">
                            <div class="share-track d-none d-sm-block">
                                <div class="share-fill" :style="{ width: `${Math.max(0, (item.revenue / maxRevenue) * 100)}%` }" />
                            </div>
                            <span class="tabular">{{ formatMoney(item.revenue) }}</span>
                        </div>
                    </template>
                    <template #[`item.share`]="{ item }">
                        <span class="tabular">{{ formatPercent(item.share) }}</span>
                    </template>
                    <template #[`item.previous`]="{ item }">
                        <span class="tabular" :class="{ 'text-disabled': !item.previous }">
                            {{ item.previous ? formatMoney(item.previous) : '—' }}
                        </span>
                    </template>
                    <template #[`item.change`]="{ item }">
                        <span v-if="change(item) === null" class="text-caption text-medium-emphasis">nuovo</span>
                        <span v-else class="tabular d-inline-flex align-center ga-1">
                            <v-icon
                                :icon="change(item) >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'"
                                :color="change(item) >= 0 ? 'success' : 'error'"
                                size="14"
                            />
                            {{ change(item) >= 0 ? '+' : '' }}{{ formatPercent(change(item)) }}
                        </span>
                    </template>
                </v-data-table>

                <v-data-table
                    v-else
                    :headers="pivotHeaders"
                    :items="customersByYear"
                    :search="search"
                    :items-per-page="25"
                    density="compact"
                    no-data-text="Nessun cliente"
                >
                    <template #[`item.name`]="{ item }">
                        <a v-bind="link(route('registry.organizations.show', item.id))" class="text-decoration-none">{{ item.name }}</a>
                    </template>
                    <template v-for="column in yearColumns" #[`item.y${column}`]="{ item }" :key="column">
                        <span class="tabular" :class="{ 'text-disabled': !item.years[column] }">
                            {{ item.years[column] ? formatMoney(item.years[column]) : '—' }}
                        </span>
                    </template>
                    <template #[`item.total`]="{ item }">
                        <strong class="tabular">{{ formatMoney(item.total) }}</strong>
                    </template>
                </v-data-table>
            </v-card>
        </template>
    </div>
</template>

<style scoped>
.stat-value {
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1.3;
    margin-top: 2px;
}

.tabular {
    font-variant-numeric: tabular-nums;
}

/* Revenue relative to the first customer: one hue, thin track of the same family. */
.share-track {
    width: 90px;
    height: 6px;
    border-radius: 3px;
    background: rgba(var(--v-theme-primary), 0.12);
    overflow: hidden;
}

.share-fill {
    height: 100%;
    border-radius: 3px;
    background: rgb(var(--v-theme-primary));
}
</style>
