<script setup>
import { computed, watch } from 'vue';
import { formatMoney, formatPercent } from '@/utils/format';

/**
 * Fields shared by the create / edit product pages.
 * `v-model:form` is an Inertia useForm() instance owned by the page.
 */
const form = defineModel('form', { type: Object, required: true });

const props = defineProps({
    types: { type: Array, required: true },
    units: { type: Array, required: true },
    billingPeriods: { type: Array, required: true },
    categories: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    nextCodes: { type: Object, default: () => ({}) },
    isNew: { type: Boolean, default: false },
});

const isService = computed(() => form.value.type === 'service');

const vatItems = computed(() =>
    props.vatRates.map((rate) => ({
        value: rate.id,
        title: rate.name,
        subtitle: rate.nature_label ?? undefined,
        disabled: !rate.is_active,
    })),
);

const margin = computed(() => {
    const price = Number(form.value.price);
    const cost = form.value.cost === '' || form.value.cost === null ? null : Number(form.value.cost);

    if (cost === null || Number.isNaN(price) || Number.isNaN(cost)) {
        return null;
    }

    return { amount: price - cost, percent: price > 0 ? ((price - cost) / price) * 100 : null };
});

const recurring = computed({
    get: () => !!form.value.billing_period,
    set: (value) => {
        form.value.billing_period = value ? 'monthly' : null;

        if (value && ['pz', 'h'].includes(form.value.unit)) {
            form.value.unit = 'mese';
        }
    },
});

// Sensible default unit when switching type on a new article.
watch(
    () => form.value.type,
    (type) => {
        if (!props.isNew) {
            return;
        }

        form.value.unit = type === 'service' ? 'h' : 'pz';

        if (type !== 'service') {
            form.value.billing_period = null;
        }
    },
);

watch(
    () => form.value.billing_period,
    (period) => {
        if (period === 'annual' && form.value.unit === 'mese') {
            form.value.unit = 'anno';
        } else if (period && period !== 'annual' && form.value.unit === 'anno') {
            form.value.unit = 'mese';
        }
    },
);
</script>

<template>
    <div>
        <v-btn-toggle v-model="form.type" mandatory color="primary" variant="outlined" divided class="mb-6">
            <v-btn value="product" prepend-icon="mdi-package-variant-closed">Prodotto</v-btn>
            <v-btn value="service" prepend-icon="mdi-account-wrench-outline">Servizio</v-btn>
        </v-btn-toggle>

        <v-row>
            <v-col cols="12" md="3">
                <v-text-field
                    v-model="form.code"
                    label="Codice"
                    :placeholder="nextCodes[form.type]"
                    :hint="isNew ? `Lascia vuoto per usare ${nextCodes[form.type]}` : ''"
                    persistent-hint
                    :error-messages="form.errors.code"
                    @update:model-value="(value) => (form.code = (value ?? '').toUpperCase())"
                />
            </v-col>
            <v-col cols="12" md="9">
                <v-text-field v-model="form.name" label="Nome" :error-messages="form.errors.name" autofocus />
            </v-col>
            <v-col cols="12" md="6">
                <v-select
                    v-model="form.category_id"
                    :items="categories"
                    item-title="name"
                    item-value="id"
                    label="Categoria"
                    clearable
                    no-data-text="Nessuna categoria: creale da Categorie e IVA"
                    :error-messages="form.errors.category_id"
                />
            </v-col>
            <v-col cols="12" md="6">
                <v-select v-model="form.unit" :items="units" label="Unità di misura" :error-messages="form.errors.unit" />
            </v-col>
        </v-row>

        <div class="text-subtitle-1 font-weight-medium mt-4 mb-3">Prezzo</div>
        <v-row>
            <v-col cols="12" sm="6" md="3">
                <v-text-field
                    v-model="form.price"
                    label="Prezzo di vendita"
                    type="number"
                    min="0"
                    step="0.01"
                    prefix="€"
                    hint="IVA esclusa"
                    persistent-hint
                    :error-messages="form.errors.price"
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-text-field
                    v-model="form.cost"
                    label="Costo di acquisto"
                    type="number"
                    min="0"
                    step="0.01"
                    prefix="€"
                    :error-messages="form.errors.cost"
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-select v-model="form.vat_rate_id" :items="vatItems" item-props label="IVA" :error-messages="form.errors.vat_rate_id" />
            </v-col>
            <v-col cols="12" sm="6" md="3" class="d-flex align-center">
                <div v-if="margin">
                    <div class="text-caption text-medium-emphasis">Margine</div>
                    <div :class="margin.amount < 0 ? 'text-error' : 'text-success'" class="font-weight-bold">
                        {{ formatMoney(margin.amount) }} · {{ formatPercent(margin.percent) }}
                    </div>
                </div>
                <div v-else class="text-caption text-medium-emphasis">Inserisci il costo per vedere il margine.</div>
            </v-col>
        </v-row>

        <template v-if="isService">
            <div class="text-subtitle-1 font-weight-medium mt-4 mb-1">Canone</div>
            <v-row align="center">
                <v-col cols="12" md="6">
                    <v-switch
                        v-model="recurring"
                        label="Servizio ricorrente (canone periodico)"
                        hint="Usato dai contratti a canone"
                        persistent-hint
                    />
                </v-col>
                <v-col v-if="recurring" cols="12" md="6">
                    <v-select
                        v-model="form.billing_period"
                        :items="billingPeriods"
                        label="Periodicità"
                        hint="Il prezzo si intende per ogni periodo"
                        persistent-hint
                        :error-messages="form.errors.billing_period"
                    />
                </v-col>
            </v-row>
        </template>

        <div class="text-subtitle-1 font-weight-medium mt-6 mb-3">Descrizioni</div>
        <v-textarea
            v-model="form.description"
            label="Descrizione"
            hint="Testo riportato nei preventivi e nelle fatture"
            persistent-hint
            rows="3"
            auto-grow
            counter="5000"
            :error-messages="form.errors.description"
            class="mb-4"
        />
        <v-textarea
            v-model="form.notes"
            label="Note interne"
            hint="Visibili solo a voi"
            persistent-hint
            rows="2"
            auto-grow
            :error-messages="form.errors.notes"
        />

        <v-switch
            v-model="form.is_active"
            label="Attivo"
            :messages="form.is_active ? 'Disponibile nei preventivi e nelle fatture.' : 'Non più proposto nei nuovi documenti.'"
            class="mt-4"
        />
    </div>
</template>
