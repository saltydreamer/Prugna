<script setup>
import { computed } from 'vue';
import ProductPicker from './ProductPicker.vue';
import { formatMoney } from '@/utils/format';
import { lineTotal } from '@/utils/quoteTotals';

/**
 * Lines of a quote (v-model): priced items, section headings and free text.
 */
const lines = defineModel({ type: Array, required: true });

const props = defineProps({
    vatRates: { type: Array, required: true },
    units: { type: Array, required: true },
    organizationId: { type: Number, default: null },
    /** Inertia form errors ("lines.3.quantity" → message). */
    errors: { type: Object, default: () => ({}) },
    defaultVatRateId: { type: Number, default: null },
    /** Quotes only: lines the customer may choose to include. */
    allowOptional: { type: Boolean, default: true },
    /** Supplier orders: prices are costs, lines can be charged back to the customer with a markup. */
    atCost: { type: Boolean, default: false },
    rebillable: { type: Boolean, default: false },
});

let uid = 0;
const key = () => `new-${Date.now()}-${uid++}`;

const vatItems = computed(() => props.vatRates.map((rate) => ({ value: rate.id, title: rate.nature ?? `${rate.rate}%`, subtitle: rate.name })));
const unitItems = computed(() => props.units.map((unit) => unit.value));

function add(line) {
    lines.value = [...lines.value, { key: key(), is_optional: false, is_selected: true, rebill: false, markup_percent: 0, ...line }];
}

function rebillAmount(line) {
    return lineTotal(line) * (1 + Number(line.markup_percent || 0) / 100);
}

function addItem(product = null) {
    add({
        type: 'item',
        product_id: product?.id ?? null,
        code: product?.code ?? null,
        description: product ? [product.name, product.description].filter(Boolean).join('\n') : '',
        unit: product?.unit ?? 'pz',
        quantity: 1,
        unit_price: product?.price ?? 0,
        discount_percent: 0,
        vat_rate_id: product?.vat_rate_id ?? props.defaultVatRateId,
    });
}

function addSection() {
    add({ type: 'section', description: '' });
}

function addText() {
    add({ type: 'text', description: '' });
}

function remove(index) {
    lines.value = lines.value.filter((_, position) => position !== index);
}

function move(index, offset) {
    const target = index + offset;

    if (target < 0 || target >= lines.value.length) {
        return;
    }

    const copy = [...lines.value];
    [copy[index], copy[target]] = [copy[target], copy[index]];
    lines.value = copy;
}

function duplicate(index) {
    const copy = [...lines.value];
    copy.splice(index + 1, 0, {
        ...lines.value[index],
        key: key(),
        id: undefined,
        order_line_id: null,
        costs: [],
        interventions: [],
    });
    lines.value = copy;
}

/**
 * An optional line starts excluded from the total: the customer chooses whether to include it.
 */
function setOptional(line, value) {
    line.is_optional = value;
    line.is_selected = !value;
}

function error(index, field) {
    return props.errors[`lines.${index}.${field}`];
}
</script>

<template>
    <div>
        <div v-if="!lines.length" class="text-center text-medium-emphasis py-6">Nessuna riga: aggiungi articoli dal listino o righe libere.</div>

        <div v-for="(line, index) in lines" :key="line.key ?? line.id" class="quote-line" :class="`quote-line--${line.type}`">
            <div class="d-flex align-start ga-2">
                <div class="d-flex flex-column quote-line__handle">
                    <v-btn icon="mdi-chevron-up" size="x-small" variant="text" :disabled="index === 0" title="Sposta su" @click="move(index, -1)" />
                    <v-btn
                        icon="mdi-chevron-down"
                        size="x-small"
                        variant="text"
                        :disabled="index === lines.length - 1"
                        title="Sposta giù"
                        @click="move(index, 1)"
                    />
                </div>

                <div class="flex-grow-1" style="min-width: 0">
                    <!-- Section heading -->
                    <v-text-field
                        v-if="line.type === 'section'"
                        v-model="line.description"
                        label="Titolo sezione"
                        density="compact"
                        prepend-inner-icon="mdi-format-header-pound"
                        :error-messages="error(index, 'description')"
                        hide-details="auto"
                        class="font-weight-bold"
                    />

                    <!-- Free text -->
                    <v-textarea
                        v-else-if="line.type === 'text'"
                        v-model="line.description"
                        label="Testo"
                        density="compact"
                        rows="1"
                        auto-grow
                        prepend-inner-icon="mdi-text"
                        :error-messages="error(index, 'description')"
                        hide-details="auto"
                    />

                    <!-- Line generated by the system (e.g. deposit deduction): only removable -->
                    <div v-else-if="line.system" class="d-flex align-center ga-2 py-2">
                        <v-icon icon="mdi-cash-minus" color="secondary" />
                        <div class="flex-grow-1 text-body-2">{{ line.description }}</div>
                        <span class="font-weight-bold text-no-wrap">{{ formatMoney(lineTotal(line)) }}</span>
                    </div>

                    <!-- Priced item -->
                    <template v-else>
                        <v-textarea
                            v-model="line.description"
                            :label="line.code ? `Descrizione · ${line.code}` : 'Descrizione'"
                            density="compact"
                            rows="1"
                            auto-grow
                            :error-messages="error(index, 'description')"
                            hide-details="auto"
                            class="mb-2"
                        />
                        <v-row dense align="center">
                            <v-col cols="4" sm="2">
                                <v-text-field
                                    v-model.number="line.quantity"
                                    label="Q.tà"
                                    type="number"
                                    min="0"
                                    step="any"
                                    density="compact"
                                    :error-messages="error(index, 'quantity')"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="4" sm="2">
                                <v-combobox v-model="line.unit" :items="unitItems" label="U.m." density="compact" hide-details="auto" />
                            </v-col>
                            <v-col cols="4" sm="3">
                                <v-text-field
                                    v-model.number="line.unit_price"
                                    :label="atCost ? 'Costo' : 'Prezzo'"
                                    type="number"
                                    step="0.01"
                                    prefix="€"
                                    density="compact"
                                    :error-messages="error(index, 'unit_price')"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="6" sm="2">
                                <v-text-field
                                    v-model.number="line.discount_percent"
                                    label="Sconto"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.5"
                                    suffix="%"
                                    density="compact"
                                    :error-messages="error(index, 'discount_percent')"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="6" sm="3">
                                <v-select
                                    v-model="line.vat_rate_id"
                                    :items="vatItems"
                                    item-props
                                    label="IVA"
                                    density="compact"
                                    :error-messages="error(index, 'vat_rate_id')"
                                    hide-details="auto"
                                />
                            </v-col>
                        </v-row>
                        <div class="d-flex align-center flex-wrap ga-4 mt-1">
                            <v-chip v-if="line.order_line_id" size="small" variant="tonal" color="info" prepend-icon="mdi-clipboard-text-outline">
                                Riga d'ordine
                            </v-chip>
                            <v-chip v-if="line.costs?.length" size="small" variant="tonal" color="secondary" prepend-icon="mdi-cart-arrow-right">
                                {{ line.costs.length > 1 ? `${line.costs.length} costi ribaltati` : 'Costo ribaltato' }}
                            </v-chip>
                            <v-chip v-if="line.interventions?.length" size="small" variant="tonal" color="secondary" prepend-icon="mdi-timer-outline">
                                {{ line.interventions.length > 1 ? `${line.interventions.length} interventi` : 'Intervento' }}
                            </v-chip>
                            <template v-if="rebillable">
                                <v-checkbox v-model="line.rebill" label="Da ribaltare al cliente" density="compact" hide-details />
                                <v-text-field
                                    v-if="line.rebill"
                                    v-model.number="line.markup_percent"
                                    label="Ricarico"
                                    type="number"
                                    min="0"
                                    step="1"
                                    suffix="%"
                                    density="compact"
                                    hide-details="auto"
                                    :error-messages="error(index, 'markup_percent')"
                                    style="max-width: 120px"
                                />
                                <span v-if="line.rebill" class="text-caption text-medium-emphasis">
                                    al cliente {{ formatMoney(rebillAmount(line)) }}
                                </span>
                            </template>
                            <v-checkbox
                                v-if="allowOptional"
                                :model-value="line.is_optional"
                                label="Facoltativa"
                                density="compact"
                                hide-details
                                @update:model-value="(value) => setOptional(line, value)"
                            />
                            <v-checkbox
                                v-if="allowOptional && line.is_optional"
                                v-model="line.is_selected"
                                label="Inclusa nel totale"
                                density="compact"
                                hide-details
                            />
                            <v-spacer />
                            <div class="text-right">
                                <span class="text-caption text-medium-emphasis mr-2">Importo</span>
                                <span class="font-weight-bold" :class="{ 'text-medium-emphasis': line.is_optional && !line.is_selected }">
                                    {{ formatMoney(lineTotal(line)) }}
                                </span>
                            </div>
                        </div>
                    </template>
                </div>

                <v-menu location="bottom end">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" size="small" variant="text" title="Azioni riga" />
                    </template>
                    <v-list density="compact">
                        <v-list-item v-if="!line.system" prepend-icon="mdi-content-copy" title="Duplica" @click="duplicate(index)" />
                        <v-list-item prepend-icon="mdi-delete-outline" base-color="error" title="Elimina" @click="remove(index)" />
                    </v-list>
                </v-menu>
            </div>
        </div>

        <div v-if="errors.lines" class="text-error text-body-2 mt-2">{{ errors.lines }}</div>

        <v-row dense class="mt-4" align="center">
            <v-col cols="12" md="6">
                <ProductPicker :organization-id="organizationId" :at-cost="atCost" @pick="addItem" />
            </v-col>
            <v-col cols="12" md="6" class="d-flex flex-wrap ga-2">
                <v-btn variant="tonal" prepend-icon="mdi-plus" @click="addItem()">Riga libera</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-format-header-pound" @click="addSection">Sezione</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-text" @click="addText">Testo</v-btn>
            </v-col>
        </v-row>
    </div>
</template>

<style scoped>
.quote-line {
    padding: 12px 0;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.quote-line--section {
    background: rgba(var(--v-theme-primary), 0.04);
}

.quote-line__handle {
    margin-top: 2px;
}
</style>
