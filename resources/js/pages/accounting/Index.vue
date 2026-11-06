<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import ColumnChart from '@/components/charts/ColumnChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatPercent } from '@/utils/format';

const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    /** ProfitAndLoss::forYear() */
    report: { type: Object, required: true },
    /** { revenue, costs, net } of the year before. */
    previous: { type: Object, required: true },
    /** Flat-rate tax estimate (null outside the forfettario). */
    estimate: { type: Object, default: null },
    parameters: { type: Object, default: null },
    /** { receivable, receivable_count, overdue, payable, payable_count, payable_overdue } */
    open: { type: Object, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();

const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const euroRounded = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
const euro = (value) => (Math.abs(value) >= 1000 ? euroRounded.format(value) : formatMoney(Math.round(value * 100) / 100));
const compact = (value) =>
    Math.abs(value) >= 1000 ? `${new Intl.NumberFormat('it-IT', { maximumFractionDigits: 1 }).format(value / 1000)}k` : String(value);

function selectYear(year) {
    router.get(route('accounting.index'), { year }, { preserveScroll: true, preserveState: true });
}

function delta(current, previous) {
    return previous ? ((current - previous) / Math.abs(previous)) * 100 : null;
}

const tiles = computed(() => [
    { label: `Incassato ${props.year}`, value: props.report.revenue, change: delta(props.report.revenue, props.previous.revenue), good: 'up' },
    { label: 'Costi pagati', value: props.report.costs, change: delta(props.report.costs, props.previous.costs), good: 'down' },
    {
        label: 'Margine operativo',
        value: props.report.operating,
        hint: props.report.revenue ? `${formatPercent((props.report.operating / props.report.revenue) * 100)} dell'incassato` : null,
    },
    { label: 'Utile dopo tasse e contributi pagati', value: props.report.net, change: delta(props.report.net, props.previous.net), good: 'up' },
]);

const costCategories = computed(() => props.report.categories.filter((item) => item.kind === 'cost'));
const maxCost = computed(() => Math.max(1, ...costCategories.value.map((item) => item.amount)));

const monthsTable = ref(false);
const monthsSeries = computed(() => [
    { name: 'Incassato', values: props.report.months.map((item) => item.revenue), tone: 'accent' },
    { name: 'Costi', values: props.report.months.map((item) => item.costs), tone: 'muted' },
]);

const inpsLabel = computed(
    () =>
        ({
            separata: `Gestione separata ${props.parameters?.inps_rate}%`,
            artigiani_commercianti: `Artigiani/commercianti${props.parameters?.inps_reduction ? ' (riduzione 35%)' : ''}`,
            none: 'Nessun contributo calcolato',
        })[props.parameters?.inps_regime] ?? '',
);
</script>

<template>
    <div>
        <PageHeader title="Conto economico" subtitle="Per cassa: ricavi quando li incassi, costi quando li paghi (solo la quota aziendale)">
            <template #actions>
                <v-btn variant="tonal" prepend-icon="mdi-bank-outline" v-bind="link(route('accounting.cash.index'))">Cassa e banca</v-btn>
                <v-btn
                    v-if="can('accounting.manage')"
                    variant="text"
                    prepend-icon="mdi-shape-outline"
                    v-bind="link(route('accounting.settings.index'))"
                >
                    Categorie e attività
                </v-btn>
            </template>
        </PageHeader>

        <div class="d-flex flex-wrap align-center ga-2 mb-4">
            <span class="text-body-2 text-medium-emphasis mr-1">Anno</span>
            <v-chip-group :model-value="year" mandatory selected-class="text-primary" @update:model-value="selectYear">
                <v-chip v-for="item in [...years].sort()" :key="item" :value="item" variant="outlined" size="small">{{ item }}</v-chip>
            </v-chip-group>
        </div>

        <v-alert v-if="!report.collected && !report.costs" type="info" variant="tonal" class="mb-6" icon="mdi-information-outline">
            Per vedere i ricavi per cassa registra gli incassi delle fatture: dalla pagina della fattura, oppure recuperando quelli di Dolibarr in
            <a v-bind="link(route('accounting.cash.index'))">Cassa e banca</a>
            . I costi arrivano dalle spese segnate come pagate.
        </v-alert>

        <v-row class="mb-2">
            <v-col v-for="tile in tiles" :key="tile.label" cols="12" sm="6" lg="3">
                <v-card class="h-100">
                    <v-card-item>
                        <v-card-subtitle class="text-wrap">{{ tile.label }}</v-card-subtitle>
                        <div class="stat-value" :class="{ 'text-error': tile.value < 0 }">{{ euro(tile.value) }}</div>
                        <div v-if="tile.change !== null && tile.change !== undefined" class="text-caption d-flex align-center ga-1">
                            <v-icon
                                :icon="tile.change >= 0 ? 'mdi-arrow-up' : 'mdi-arrow-down'"
                                :color="tile.change >= 0 === (tile.good === 'up') ? 'success' : 'error'"
                                size="14"
                            />
                            <span>{{ tile.change >= 0 ? '+' : '' }}{{ formatPercent(tile.change) }} sul {{ year - 1 }}</span>
                        </div>
                        <div v-else-if="tile.hint" class="text-caption text-medium-emphasis">{{ tile.hint }}</div>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-alert v-if="report.unclassified.count" type="warning" variant="tonal" density="compact" class="mb-6">
            {{ report.unclassified.count }} movimenti senza categoria (entrate {{ formatMoney(report.unclassified.in) }}, uscite
            {{ formatMoney(report.unclassified.out) }}) restano fuori dal conto economico: se sono costi o ricavi, dai loro una categoria in
            <a v-bind="link(route('accounting.cash.index', { year }))">Cassa e banca</a>
            .
        </v-alert>

        <v-card class="mb-6">
            <v-card-item>
                <v-card-title>Mese per mese</v-card-title>
                <v-card-subtitle>Incassato e costi pagati nel {{ year }}</v-card-subtitle>
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
                    :label="`Incassato e costi mese per mese nel ${year}`"
                    :categories="MONTHS"
                    :series="monthsSeries"
                    :format="formatMoney"
                    :format-tick="compact"
                />
                <v-table v-else density="compact">
                    <thead>
                        <tr>
                            <th>Mese</th>
                            <th class="text-end">Incassato</th>
                            <th class="text-end">Costi</th>
                            <th class="text-end">Imposte e contributi</th>
                            <th class="text-end">Risultato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in report.months" :key="item.month">
                            <td>{{ MONTHS[index] }}</td>
                            <td class="text-end tabular">{{ formatMoney(item.revenue) }}</td>
                            <td class="text-end tabular">{{ formatMoney(item.costs) }}</td>
                            <td class="text-end tabular">{{ formatMoney(item.taxes) }}</td>
                            <td class="text-end tabular" :class="{ 'text-error': item.net < 0 }">{{ formatMoney(item.net) }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
        </v-card>

        <v-row>
            <v-col cols="12" lg="6">
                <v-card class="mb-6" title="Conto economico" :subtitle="String(year)">
                    <v-table density="compact">
                        <tbody>
                            <tr>
                                <td>
                                    Incassi da fatture
                                    <span class="text-caption text-medium-emphasis">(senza IVA, note di credito sottratte)</span>
                                </td>
                                <td class="text-end tabular">{{ formatMoney(report.collected) }}</td>
                            </tr>
                            <tr v-if="report.other_revenue">
                                <td>Altri ricavi</td>
                                <td class="text-end tabular">{{ formatMoney(report.other_revenue) }}</td>
                            </tr>
                            <tr class="font-weight-medium">
                                <td>Ricavi</td>
                                <td class="text-end tabular">{{ formatMoney(report.revenue) }}</td>
                            </tr>
                            <tr v-for="item in costCategories" :key="item.id ?? 'none'">
                                <td>
                                    <div class="d-flex align-center ga-3">
                                        <span class="flex-grow-1">{{ item.name }}</span>
                                        <div class="share-track d-none d-sm-block">
                                            <div class="share-bar" :style="{ width: `${(item.amount / maxCost) * 100}%` }" />
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end tabular">− {{ formatMoney(item.amount) }}</td>
                            </tr>
                            <tr class="font-weight-medium">
                                <td>Margine operativo</td>
                                <td class="text-end tabular" :class="{ 'text-error': report.operating < 0 }">{{ formatMoney(report.operating) }}</td>
                            </tr>
                            <tr>
                                <td>Imposte pagate</td>
                                <td class="text-end tabular">− {{ formatMoney(report.taxes) }}</td>
                            </tr>
                            <tr>
                                <td>Contributi previdenziali pagati</td>
                                <td class="text-end tabular">− {{ formatMoney(report.contributions) }}</td>
                            </tr>
                            <tr class="font-weight-bold">
                                <td>Utile</td>
                                <td class="text-end tabular" :class="{ 'text-error': report.net < 0 }">{{ formatMoney(report.net) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>

                <v-card
                    class="mb-6"
                    title="Per linea di attività"
                    subtitle="Ricavi dai prodotti delle righe fattura, costi dalle spese e dai movimenti"
                >
                    <v-table v-if="report.lines.length" density="compact">
                        <thead>
                            <tr>
                                <th>Linea</th>
                                <th class="text-end">Ricavi</th>
                                <th class="text-end">Costi</th>
                                <th class="text-end">Margine</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in report.lines" :key="item.id">
                                <td :class="{ 'text-medium-emphasis': item.id === 0 }">{{ item.name }}</td>
                                <td class="text-end tabular">{{ formatMoney(item.revenue) }}</td>
                                <td class="text-end tabular">{{ formatMoney(item.costs) }}</td>
                                <td class="text-end tabular font-weight-medium" :class="{ 'text-error': item.margin < 0 }">
                                    {{ formatMoney(item.margin) }}
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessun incasso o costo nel {{ year }}.</v-card-text>
                    <v-card-text v-if="can('accounting.manage')" class="text-caption text-medium-emphasis pt-0">
                        Crea le linee in
                        <a v-bind="link(route('accounting.settings.index'))">Categorie e attività</a>
                        e assegnale ai prodotti del listino e alle spese.
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="6">
                <v-card v-if="estimate" class="mb-6" title="Stima tasse e contributi" subtitle="Regime forfettario, sull'incassato dell'anno">
                    <v-card-text>
                        <v-alert v-if="estimate.over_limit" type="warning" variant="tonal" density="compact" class="mb-3">
                            Incassato oltre 85.000 €: dall'anno prossimo si esce dal forfettario (oltre 100.000 € subito). Parlane con il
                            commercialista.
                        </v-alert>
                        <v-table density="compact">
                            <tbody>
                                <tr>
                                    <td>Incassato</td>
                                    <td class="text-end tabular">{{ formatMoney(estimate.collected) }}</td>
                                </tr>
                                <tr>
                                    <td>
                                        Reddito lordo
                                        <span class="text-caption text-medium-emphasis">(coefficiente {{ parameters.coefficient }}%)</span>
                                    </td>
                                    <td class="text-end tabular">{{ formatMoney(estimate.gross_income) }}</td>
                                </tr>
                                <tr>
                                    <td>
                                        Contributi versati nell'anno
                                        <span class="text-caption text-medium-emphasis">(si deducono)</span>
                                    </td>
                                    <td class="text-end tabular">− {{ formatMoney(estimate.contributions_paid) }}</td>
                                </tr>
                                <tr>
                                    <td>Reddito imponibile</td>
                                    <td class="text-end tabular">{{ formatMoney(estimate.taxable) }}</td>
                                </tr>
                                <tr class="font-weight-medium">
                                    <td>Imposta sostitutiva {{ parameters.tax_rate }}%</td>
                                    <td class="text-end tabular">{{ formatMoney(estimate.tax) }}</td>
                                </tr>
                                <tr class="font-weight-medium">
                                    <td>
                                        Contributi INPS stimati
                                        <span class="text-caption text-medium-emphasis">({{ inpsLabel }})</span>
                                    </td>
                                    <td class="text-end tabular">{{ formatMoney(estimate.contributions_due) }}</td>
                                </tr>
                                <tr class="font-weight-bold">
                                    <td>Da accantonare</td>
                                    <td class="text-end tabular">{{ formatMoney(estimate.set_aside) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                        <div class="text-body-2 mt-3">
                            Circa
                            <strong>{{ formatPercent(estimate.set_aside_percent) }}</strong>
                            di ogni incasso. Già versati nel {{ year }}: imposte {{ formatMoney(estimate.taxes_paid) }}, contributi
                            {{ formatMoney(estimate.contributions_paid) }}.
                        </div>
                        <div class="text-caption text-medium-emphasis mt-2">
                            Stima indicativa: acconti, saldi e scadenze li decide la dichiarazione dei redditi. Controlla i parametri con il
                            commercialista e aggiornali ogni anno
                            <template v-if="can('accounting.manage')">
                                in
                                <a v-bind="link(route('accounting.settings.index'))">Categorie e attività</a>
                            </template>
                            .
                        </div>
                    </v-card-text>
                </v-card>

                <v-card class="mb-6" title="Da incassare e da pagare">
                    <v-list density="compact">
                        <v-list-item
                            prepend-icon="mdi-cash-clock"
                            :title="`${formatMoney(open.receivable)} da ${open.receivable_count} fatture`"
                            :subtitle="open.overdue ? `di cui scaduti ${formatMoney(open.overdue)}` : 'nessuna scaduta'"
                            v-bind="link(route('accounting.cash.index'))"
                        />
                        <v-list-item
                            prepend-icon="mdi-receipt-text-clock-outline"
                            :title="`${formatMoney(open.payable)} da ${open.payable_count} spese da pagare`"
                            :subtitle="open.payable_overdue ? `di cui scaduti ${formatMoney(open.payable_overdue)}` : 'nessuna scaduta'"
                            v-bind="link(route('purchases.expenses.index', { status: 'unpaid' }))"
                        />
                    </v-list>
                </v-card>
            </v-col>
        </v-row>
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

.share-track {
    width: 90px;
    height: 6px;
    border-radius: 3px;
    background: rgba(var(--v-theme-on-surface), 0.08);
    overflow: hidden;
}

.share-bar {
    height: 100%;
    border-radius: 3px;
    background: rgba(var(--v-theme-on-surface), 0.32);
}
</style>
