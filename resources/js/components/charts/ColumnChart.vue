<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Column chart in plain SVG: one or more series per category, thin columns (max 24 px) with
 * rounded data ends, hairline grid, per-column tooltip (hover and keyboard focus).
 * With a single series and `highlight`, the highlighted column takes the accent color and
 * the others recede in gray (emphasis); with more series each one has its tone and a legend.
 */
const props = defineProps({
    /** Labels of the categories, left to right. */
    categories: { type: Array, required: true },
    /** [{ name, values: number[], tone: 'accent' | 'muted' }] */
    series: { type: Array, required: true },
    /** Single series: index of the column in the accent color. */
    highlight: { type: Number, default: null },
    /** Accessible name of the chart. */
    label: { type: String, required: true },
    format: { type: Function, default: (value) => String(value) },
    formatTick: { type: Function, default: (value) => String(value) },
    height: { type: Number, default: 220 },
    /** Columns can be clicked (or chosen with Enter): emits `select` with the index. */
    selectable: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);

const root = ref(null);
const width = ref(640);
const hovered = ref(null);
let observer = null;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(260, Math.floor(entry.contentRect.width));
    });
    observer.observe(root.value);
});

onBeforeUnmount(() => observer?.disconnect());

const margin = { top: 22, right: 4, bottom: 26, left: 48 };
const plotWidth = computed(() => width.value - margin.left - margin.right);
const plotHeight = computed(() => props.height - margin.top - margin.bottom);

function niceStep(raw) {
    const power = 10 ** Math.floor(Math.log10(raw));
    const fraction = raw / power;

    return (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10) * power;
}

const scale = computed(() => {
    const values = props.series.flatMap((serie) => serie.values);
    const max = Math.max(0, ...values);
    const min = Math.min(0, ...values);
    const step = niceStep(Math.max(max - min, 1) / 4);
    const top = Math.max(step, Math.ceil(max / step) * step);
    const bottom = Math.floor(min / step) * step;
    const ticks = [];

    for (let tick = bottom; tick <= top + step / 2; tick += step) {
        ticks.push(Math.round(tick * 100) / 100);
    }

    const y = (value) => margin.top + ((top - value) / (top - bottom)) * plotHeight.value;

    return { ticks, y, zero: y(0) };
});

const band = computed(() => plotWidth.value / Math.max(1, props.categories.length));
const barWidth = computed(() => {
    const count = props.series.length;

    return Math.max(2, Math.min(24, (band.value * 0.7 - (count - 1) * 2) / count));
});

function tone(seriesIndex, categoryIndex) {
    if (props.series.length === 1 && props.highlight !== null) {
        return categoryIndex === props.highlight ? 'accent' : 'muted';
    }

    return props.series[seriesIndex].tone ?? 'accent';
}

/** Column with 4 px rounded corners at the data end, square on the baseline. */
function columnPath(x, w, value) {
    const base = scale.value.zero;
    const end = scale.value.y(value);
    const height = Math.abs(end - base);

    if (height < 0.5) {
        return '';
    }

    const r = Math.min(4, w / 2, height);
    const dir = value >= 0 ? 1 : -1;

    return `M${x},${base} V${end + dir * r} Q${x},${end} ${x + r},${end} H${x + w - r} Q${x + w},${end} ${x + w},${end + dir * r} V${base} Z`;
}

const groups = computed(() =>
    props.categories.map((category, index) => {
        const groupWidth = props.series.length * barWidth.value + (props.series.length - 1) * 2;
        const start = margin.left + index * band.value + (band.value - groupWidth) / 2;
        const bars = props.series.map((serie, seriesIndex) => {
            const value = serie.values[index] ?? 0;
            const x = start + seriesIndex * (barWidth.value + 2);

            return { key: `${index}-${seriesIndex}`, value, x, path: columnPath(x, barWidth.value, value), tone: tone(seriesIndex, index) };
        });

        return {
            index,
            category,
            center: margin.left + (index + 0.5) * band.value,
            top: Math.min(...bars.map((bar) => scale.value.y(Math.max(bar.value, 0)))),
            bars,
        };
    }),
);

// Category labels thinned out only when they would overlap (about 6.5 px per character).
const labelEvery = computed(() => {
    const longest = Math.max(1, ...props.categories.map((category) => String(category).length));

    return Math.max(1, Math.ceil((longest * 6.5 + 10) / band.value));
});

const highlighted = computed(() => (props.series.length === 1 && props.highlight !== null ? groups.value[props.highlight] : null));

const tooltip = computed(() => {
    if (hovered.value === null) {
        return null;
    }

    const group = groups.value[hovered.value];

    return {
        title: group.category,
        left: Math.min(Math.max(group.center, 90), width.value - 90),
        top: Math.max(group.top - 10, 8),
        rows: props.series.map((serie, seriesIndex) => ({
            name: serie.name,
            value: props.format(serie.values[hovered.value] ?? 0),
            tone: tone(seriesIndex, hovered.value),
        })),
    };
});

function choose(index) {
    if (props.selectable) {
        emit('select', index);
    }
}
</script>

<template>
    <div ref="root" class="column-chart">
        <div v-if="series.length > 1" class="column-chart__legend">
            <span v-for="serie in series" :key="serie.name" class="column-chart__legend-item">
                <span class="column-chart__swatch" :class="`column-chart__swatch--${serie.tone ?? 'accent'}`" />
                {{ serie.name }}
            </span>
        </div>

        <svg :width="width" :height="height" role="img" :aria-label="label">
            <g class="column-chart__grid">
                <line v-for="tick in scale.ticks" :key="tick" :x1="margin.left" :x2="width - margin.right" :y1="scale.y(tick)" :y2="scale.y(tick)" />
            </g>
            <line class="column-chart__baseline" :x1="margin.left" :x2="width - margin.right" :y1="scale.zero" :y2="scale.zero" />
            <g class="column-chart__ticks">
                <text v-for="tick in scale.ticks" :key="tick" :x="margin.left - 8" :y="scale.y(tick)" dy="0.32em" text-anchor="end">
                    {{ formatTick(tick) }}
                </text>
            </g>

            <g v-for="group in groups" :key="group.index" :class="{ 'is-hovered': hovered === group.index }">
                <path v-for="bar in group.bars" :key="bar.key" :d="bar.path" :class="`column-chart__mark column-chart__mark--${bar.tone}`" />
                <text v-if="group.index % labelEvery === 0" class="column-chart__category" :x="group.center" :y="height - 8" text-anchor="middle">
                    {{ group.category }}
                </text>
            </g>

            <text v-if="highlighted" class="column-chart__value" :x="highlighted.center" :y="highlighted.top - 6" text-anchor="middle">
                {{ format(highlighted.bars[0].value) }}
            </text>

            <!-- Hit areas: the whole band of each category, bigger than the columns. -->
            <rect
                v-for="group in groups"
                :key="`hit-${group.index}`"
                class="column-chart__hit"
                :class="{ 'is-selectable': selectable }"
                :x="margin.left + group.index * band"
                :y="margin.top"
                :width="band"
                :height="plotHeight"
                tabindex="0"
                :aria-label="`${group.category}: ${group.bars.map((bar, index) => `${series[index].name} ${format(bar.value)}`).join(', ')}`"
                @mouseenter="hovered = group.index"
                @mouseleave="hovered = null"
                @focus="hovered = group.index"
                @blur="hovered = null"
                @click="choose(group.index)"
                @keydown.enter="choose(group.index)"
            />
        </svg>

        <div v-if="tooltip" class="column-chart__tooltip" :style="{ left: `${tooltip.left}px`, top: `${tooltip.top}px` }">
            <div class="column-chart__tooltip-title">{{ tooltip.title }}</div>
            <div v-for="row in tooltip.rows" :key="row.name" class="column-chart__tooltip-row">
                <span class="column-chart__key" :class="`column-chart__key--${row.tone}`" />
                <strong>{{ row.value }}</strong>
                <span v-if="series.length > 1" class="column-chart__tooltip-name">{{ row.name }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.column-chart {
    position: relative;
    width: 100%;
    --chart-accent: rgb(var(--v-theme-primary));
    /* De-emphasis gray from the theme ink: light gray on light, dark gray on dark. */
    --chart-muted: rgba(var(--v-theme-on-surface), 0.28);
    --chart-ink: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

svg {
    display: block;
    overflow: visible;
}

.column-chart__grid line {
    stroke: rgba(var(--v-theme-on-surface), 0.08);
    stroke-width: 1;
}

.column-chart__baseline {
    stroke: rgba(var(--v-theme-on-surface), 0.24);
    stroke-width: 1;
}

.column-chart__ticks text,
.column-chart__category {
    fill: var(--chart-ink);
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}

.column-chart__value {
    fill: rgb(var(--v-theme-on-surface));
    font-size: 12px;
    font-weight: 600;
}

.column-chart__mark--accent {
    fill: var(--chart-accent);
}

.column-chart__mark--muted {
    fill: var(--chart-muted);
}

.is-hovered .column-chart__mark {
    opacity: 0.8;
}

.column-chart__hit {
    fill: transparent;
    outline: none;
}

.column-chart__hit.is-selectable {
    cursor: pointer;
}

.column-chart__hit:focus-visible {
    stroke: rgba(var(--v-theme-primary), 0.5);
    stroke-width: 1;
}

.column-chart__legend {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 8px;
    font-size: 12px;
    color: var(--chart-ink);
}

.column-chart__legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.column-chart__swatch {
    width: 10px;
    height: 10px;
    border-radius: 2px;
}

.column-chart__swatch--accent,
.column-chart__key--accent {
    background: var(--chart-accent);
}

.column-chart__swatch--muted,
.column-chart__key--muted {
    background: var(--chart-muted);
}

.column-chart__tooltip {
    position: absolute;
    transform: translate(-50%, -100%);
    pointer-events: none;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
    border-radius: 6px;
    padding: 6px 10px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.column-chart__tooltip-title {
    color: var(--chart-ink);
    margin-bottom: 2px;
}

.column-chart__tooltip-row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-variant-numeric: tabular-nums;
}

.column-chart__tooltip-name {
    color: var(--chart-ink);
}

.column-chart__key {
    width: 12px;
    height: 2px;
    border-radius: 1px;
}
</style>
