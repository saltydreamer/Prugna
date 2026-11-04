<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import ProductPicker from '@/components/sales/ProductPicker.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatMoney } from '@/utils/format';
import { formatHours, formatMinutes, roundUp, ROUNDING_LABELS } from '@/utils/duration';
import { http } from '@/utils/http';

const props = defineProps({
    /** null when creating. */
    intervention: { type: Object, default: null },
    defaults: { type: Object, default: null },
    customers: { type: Array, required: true },
    categories: { type: Array, required: true },
    technicians: { type: Array, default: null },
    modes: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    units: { type: Array, required: true },
    rounding: { type: Number, required: true },
    /** Services configured for call-out, km and travel ({ id, name } or null). */
    extras: { type: Object, required: true },
});

const { link } = useInertiaLink();
const isNew = computed(() => !props.intervention);
const source = props.intervention ?? props.defaults;
const running = computed(() => Boolean(props.intervention?.running));

/**
 * ISO date (UTC) ⇄ value of a datetime-local input (local time).
 */
function toLocalInput(iso) {
    const date = new Date(iso);
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const form = useForm({
    organization_id: source.organization_id ?? null,
    location_id: source.location_id ?? null,
    contact_id: source.contact_id ?? null,
    order_id: source.order_id ?? null,
    category_id: source.category_id ?? null,
    mode: source.mode ?? 'remote',
    technician_id: source.technician_id ?? null,
    started_at: toLocalInput(source.started_at),
    duration_minutes: source.duration_minutes,
    description: source.description ?? '',
    internal_notes: source.internal_notes ?? '',
    billable: source.billable ?? true,
    callout: source.callout ?? false,
    km: source.km ?? 0,
    travel_minutes: source.travel_minutes ?? 0,
    skip_contract: source.skip_contract ?? false,
    materials: (source.materials ?? []).map((material, index) => ({ ...material, key: `material-${material.id ?? index}` })),
});

// Duration as hours + minutes.
const durationHours = ref(source.duration_minutes != null ? Math.floor(source.duration_minutes / 60) : null);
const durationMinutes = ref(source.duration_minutes != null ? source.duration_minutes % 60 : null);
watch([durationHours, durationMinutes], ([hours, minutes]) => {
    form.duration_minutes = hours === null && minutes === null ? null : (Number(hours) || 0) * 60 + (Number(minutes) || 0) || null;
});

const travelHours = ref(Math.floor((source.travel_minutes ?? 0) / 60));
const travelMinutes = ref((source.travel_minutes ?? 0) % 60);
watch([travelHours, travelMinutes], ([hours, minutes]) => (form.travel_minutes = (Number(hours) || 0) * 60 + (Number(minutes) || 0)));

const endsAt = computed(() => {
    if (!form.started_at || !form.duration_minutes) {
        return null;
    }

    const end = new Date(new Date(form.started_at).getTime() + form.duration_minutes * 60000);

    return end.toLocaleString('it-IT', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
});

const billable = computed(() => roundUp(form.duration_minutes, props.rounding));
const onsite = computed(() => form.mode === 'onsite');
const category = computed(() => props.categories.find((item) => item.value === form.category_id));
const missingService = computed(() => category.value && !(onsite.value ? category.value.has_onsite : category.value.has_remote));

// --- Customer details ----------------------------------------------------------------------
const locations = ref([]);
const contacts = ref([]);
const orders = ref([]);
const contracts = ref([]);

async function loadCustomer(reset) {
    if (reset) {
        form.location_id = null;
        form.contact_id = null;
        form.order_id = null;
    }

    locations.value = [];
    contacts.value = [];
    orders.value = [];
    contracts.value = [];

    if (!form.organization_id) {
        return;
    }

    const data = await http.get(route('interventions.customer', form.organization_id));
    locations.value = data.locations;
    contacts.value = data.contacts;
    orders.value = data.orders;
    contracts.value = data.contracts ?? [];

    // The current order may be completed: keep it selectable.
    if (props.intervention?.order && !orders.value.some((order) => order.value === props.intervention.order.id)) {
        orders.value.push({ value: props.intervention.order.id, title: props.intervention.order.number, subtitle: props.intervention.order.title });
    }

    if (reset) {
        form.location_id = locations.value[0]?.value ?? null;
    }
}

loadCustomer(false);
watch(
    () => form.organization_id,
    () => loadCustomer(true),
);

// --- Hour contract -----------------------------------------------------------------------------
const localDate = computed(() => (form.started_at ? form.started_at.slice(0, 10) : null));
const contract = computed(() => {
    if (!localDate.value) {
        return null;
    }

    const candidates = contracts.value
        .filter((item) => item.starts_on <= localDate.value && (!item.ends_on || item.ends_on >= localDate.value))
        .filter((item) => !item.mode || item.mode === form.mode)
        .filter((item) => !item.category_ids.length || item.category_ids.includes(form.category_id))
        .map((item) => ({
            ...item,
            // Hours already taken by this intervention go back to the residual while editing.
            available: item.remaining_minutes + (props.intervention?.contract_id === item.id ? props.intervention.contract_minutes : 0),
        }));

    return (
        candidates.find((item) => item.id === props.intervention?.contract_id) ??
        candidates.find((item) => item.available > 0) ??
        candidates[0] ??
        null
    );
});
const fromContract = computed(() =>
    contract.value && !form.skip_contract && form.billable ? Math.min(contract.value.available, billable.value) : 0,
);

// --- Materials -----------------------------------------------------------------------------
const defaultVatRateId = computed(() => props.vatRates.find((rate) => rate.is_default)?.id ?? props.vatRates[0]?.id ?? null);
const vatItems = computed(() => props.vatRates.map((rate) => ({ value: rate.id, title: rate.nature ?? `${rate.rate}%` })));
const unitItems = computed(() => props.units.map((unit) => unit.value));
let uid = 0;

function addMaterial(product = null) {
    form.materials.push({
        key: `new-${Date.now()}-${uid++}`,
        product_id: product?.id ?? null,
        code: product?.code ?? null,
        description: product?.name ?? '',
        unit: product?.unit ?? 'pz',
        quantity: 1,
        unit_price: product?.price ?? 0,
        vat_rate_id: product?.vat_rate_id ?? defaultVatRateId.value,
    });
}

function removeMaterial(index) {
    form.materials.splice(index, 1);
}

const materialsTotal = computed(() =>
    form.materials.reduce((sum, material) => sum + Number(material.quantity || 0) * Number(material.unit_price || 0), 0),
);

function submit() {
    // eslint-disable-next-line no-unused-vars
    const clean = ({ key, id, total, ...material }) => material;
    const transform = (data) => ({
        ...data,
        started_at: data.started_at ? new Date(data.started_at).toISOString() : null,
        technician_id: props.technicians ? data.technician_id : undefined,
        materials: onsite.value ? data.materials.map(clean) : [],
    });

    if (isNew.value) {
        form.transform(transform).post(route('interventions.store'));
    } else {
        form.transform(transform).put(route('interventions.update', props.intervention.id));
    }
}

const backUrl = computed(() => (isNew.value ? route('interventions.index') : route('interventions.show', props.intervention.id)));
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Registra intervento' : `Intervento ${intervention.number}`"
            :subtitle="isNew ? 'Lavoro da remoto o presso il cliente' : 'Modifica'"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(backUrl)">{{ isNew ? 'Interventi' : 'Scheda' }}</v-btn>
            </template>
        </PageHeader>

        <v-alert v-if="!categories.length" type="warning" variant="tonal" class="mb-6">
            Nessuna categoria di intervento: creala prima in Impostazioni interventi, con le tariffe orarie.
        </v-alert>

        <v-alert v-if="running" type="info" variant="tonal" class="mb-6" icon="mdi-timer-outline">
            Il timer è in corso: la durata si compila quando premi «Termina» nella scheda o nella barra in alto.
        </v-alert>

        <v-form @submit.prevent="submit">
            <v-row>
                <v-col cols="12" lg="8">
                    <v-card class="mb-6" title="Cliente">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12">
                                    <v-autocomplete
                                        v-model="form.organization_id"
                                        :items="customers"
                                        label="Cliente"
                                        :error-messages="form.errors.organization_id"
                                    >
                                        <template #item="{ props: itemProps, item }">
                                            <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                                        </template>
                                    </v-autocomplete>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-select
                                        v-model="form.location_id"
                                        :items="locations"
                                        label="Sede"
                                        clearable
                                        :disabled="!locations.length"
                                        :error-messages="form.errors.location_id"
                                    >
                                        <template #item="{ props: itemProps, item }">
                                            <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                                        </template>
                                    </v-select>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-select
                                        v-model="form.contact_id"
                                        :items="contacts"
                                        label="Referente"
                                        clearable
                                        :disabled="!contacts.length"
                                        :error-messages="form.errors.contact_id"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-select
                                        v-model="form.order_id"
                                        :items="orders"
                                        label="Ordine (facoltativo)"
                                        clearable
                                        :disabled="!orders.length"
                                        :hint="
                                            orders.length
                                                ? 'Collega l\'intervento a un ordine per fatturarlo da lì'
                                                : 'Nessun ordine aperto per questo cliente'
                                        "
                                        persistent-hint
                                        :error-messages="form.errors.order_id"
                                    >
                                        <template #item="{ props: itemProps, item }">
                                            <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                                        </template>
                                    </v-select>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Lavoro svolto">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-select
                                        v-model="form.category_id"
                                        :items="categories"
                                        label="Categoria"
                                        :error-messages="form.errors.category_id"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-btn-toggle
                                        v-model="form.mode"
                                        mandatory
                                        color="primary"
                                        variant="outlined"
                                        divided
                                        class="w-100"
                                        style="height: 40px"
                                    >
                                        <v-btn
                                            v-for="mode in modes"
                                            :key="mode.value"
                                            :value="mode.value"
                                            :prepend-icon="mode.icon"
                                            class="flex-grow-1"
                                        >
                                            {{ mode.title }}
                                        </v-btn>
                                    </v-btn-toggle>
                                </v-col>
                                <v-col v-if="missingService" cols="12">
                                    <v-alert type="warning" variant="tonal" density="compact">
                                        La categoria non ha ancora il servizio {{ onsite ? 'on site' : 'da remoto' }}: puoi registrare l'intervento,
                                        ma per fatturarlo va impostato in Impostazioni interventi.
                                    </v-alert>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="form.started_at"
                                        type="datetime-local"
                                        label="Inizio"
                                        :error-messages="form.errors.started_at"
                                    />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field
                                        v-model.number="durationHours"
                                        type="number"
                                        min="0"
                                        max="24"
                                        label="Durata"
                                        suffix="h"
                                        :disabled="running"
                                        :error-messages="form.errors.duration_minutes"
                                    />
                                </v-col>
                                <v-col cols="6" md="3">
                                    <v-text-field
                                        v-model.number="durationMinutes"
                                        type="number"
                                        min="0"
                                        max="59"
                                        step="5"
                                        label="Minuti"
                                        suffix="min"
                                        :disabled="running"
                                    />
                                </v-col>
                                <v-col v-if="form.duration_minutes" cols="12" class="text-body-2 text-medium-emphasis mt-n2 mb-2">
                                    Fine: {{ endsAt }} ·
                                    <template v-if="form.billable">
                                        fatturate
                                        <strong>{{ formatHours(billable) }}</strong>
                                        <span v-if="billable !== form.duration_minutes">
                                            (arrotondato {{ ROUNDING_LABELS[rounding] ?? `a ${rounding} min` }})
                                        </span>
                                    </template>
                                    <template v-else>non fatturabile</template>
                                </v-col>
                                <v-col cols="12">
                                    <v-textarea
                                        v-model="form.description"
                                        label="Descrizione del lavoro svolto"
                                        hint="Compare in fattura e sul rapportino"
                                        persistent-hint
                                        rows="4"
                                        auto-grow
                                        :error-messages="form.errors.description"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-textarea
                                        v-model="form.internal_notes"
                                        label="Note interne"
                                        hint="Solo per voi: non vanno al cliente"
                                        persistent-hint
                                        rows="2"
                                        auto-grow
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card v-if="onsite" class="mb-6" title="Uscita, viaggio e materiali">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="4">
                                    <v-switch v-model="form.callout" label="Diritto di uscita" color="primary" hide-details inset />
                                    <div v-if="form.callout && !extras.callout" class="text-caption text-warning">Servizio non impostato</div>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field
                                        v-model.number="form.km"
                                        type="number"
                                        min="0"
                                        step="0.1"
                                        label="Km percorsi (andata e ritorno)"
                                        suffix="km"
                                        :error-messages="form.errors.km"
                                        :hint="form.km && !extras.km ? 'Servizio del rimborso km non impostato' : ''"
                                        persistent-hint
                                    />
                                </v-col>
                                <v-col cols="6" md="2">
                                    <v-text-field
                                        v-model.number="travelHours"
                                        type="number"
                                        min="0"
                                        label="Viaggio"
                                        suffix="h"
                                        :error-messages="form.errors.travel_minutes"
                                    />
                                </v-col>
                                <v-col cols="6" md="2">
                                    <v-text-field
                                        v-model.number="travelMinutes"
                                        type="number"
                                        min="0"
                                        max="59"
                                        step="5"
                                        label="Minuti"
                                        suffix="min"
                                    />
                                </v-col>
                                <v-col v-if="form.travel_minutes" cols="12" class="text-caption text-medium-emphasis mt-n2">
                                    Viaggio fatturato: {{ formatHours(roundUp(form.travel_minutes, rounding)) }}
                                    <span v-if="!extras.travel" class="text-warning">· servizio del tempo di viaggio non impostato</span>
                                </v-col>
                            </v-row>

                            <div class="text-subtitle-2 mt-4 mb-2">Materiali usati</div>
                            <div v-for="(material, index) in form.materials" :key="material.key" class="d-flex flex-wrap align-start ga-2 mb-2">
                                <v-text-field
                                    v-model="material.description"
                                    label="Descrizione"
                                    density="compact"
                                    class="flex-grow-1"
                                    style="min-width: 220px"
                                    :error-messages="form.errors[`materials.${index}.description`]"
                                />
                                <v-text-field
                                    v-model.number="material.quantity"
                                    type="number"
                                    label="Q.tà"
                                    density="compact"
                                    style="max-width: 90px"
                                    :error-messages="form.errors[`materials.${index}.quantity`]"
                                />
                                <v-select v-model="material.unit" :items="unitItems" label="U.m." density="compact" style="max-width: 100px" />
                                <v-text-field
                                    v-model.number="material.unit_price"
                                    type="number"
                                    step="0.01"
                                    label="Prezzo"
                                    prefix="€"
                                    density="compact"
                                    style="max-width: 130px"
                                    :error-messages="form.errors[`materials.${index}.unit_price`]"
                                />
                                <v-select v-model="material.vat_rate_id" :items="vatItems" label="IVA" density="compact" style="max-width: 100px" />
                                <v-btn icon="mdi-delete-outline" variant="text" size="small" title="Rimuovi" @click="removeMaterial(index)" />
                            </div>
                            <div class="d-flex flex-wrap align-center ga-2">
                                <div style="min-width: 280px; flex: 1">
                                    <ProductPicker :organization-id="form.organization_id" @pick="addMaterial" />
                                </div>
                                <v-btn variant="text" prepend-icon="mdi-plus" @click="addMaterial()">Riga libera</v-btn>
                                <v-spacer />
                                <span v-if="form.materials.length" class="text-body-2">
                                    Materiali:
                                    <strong>{{ formatMoney(materialsTotal) }}</strong>
                                    + IVA
                                </span>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <v-card class="mb-6">
                        <v-card-text>
                            <v-select v-if="technicians" v-model="form.technician_id" :items="technicians" label="Tecnico" class="mb-2" />
                            <v-alert
                                v-if="contract && form.billable"
                                type="info"
                                variant="tonal"
                                density="compact"
                                class="mb-4"
                                icon="mdi-timer-sand-complete"
                            >
                                <div class="text-body-2">
                                    Monte ore
                                    <strong>{{ contract.number }}</strong>
                                    : residuo {{ formatHours(contract.available) }} su {{ formatHours(contract.total_minutes) }}.
                                </div>
                                <div v-if="!form.skip_contract && form.duration_minutes" class="text-body-2">
                                    Scala
                                    <strong>{{ formatHours(fromContract) }}</strong>
                                    <template v-if="billable > fromContract">, in fattura {{ formatHours(billable - fromContract) }}</template>
                                </div>
                                <v-checkbox v-model="form.skip_contract" label="Non scalare dal monte ore" density="compact" hide-details />
                            </v-alert>
                            <v-switch
                                v-model="form.billable"
                                label="Da fatturare al cliente"
                                color="primary"
                                inset
                                hint="Spegnilo per lavori in garanzia o non addebitabili"
                                persistent-hint
                            />
                            <v-divider class="my-4" />
                            <div class="d-flex justify-space-between text-body-2 mb-1">
                                <span>Durata</span>
                                <span>{{ form.duration_minutes ? formatMinutes(form.duration_minutes) : '—' }}</span>
                            </div>
                            <div class="d-flex justify-space-between text-body-2">
                                <span>Ore fatturate</span>
                                <strong>{{ form.billable && form.duration_minutes ? formatHours(billable) : '—' }}</strong>
                            </div>
                        </v-card-text>
                        <v-card-actions>
                            <v-btn
                                type="submit"
                                color="primary"
                                variant="flat"
                                block
                                size="large"
                                :loading="form.processing"
                                prepend-icon="mdi-content-save-outline"
                            >
                                Salva intervento
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-col>
            </v-row>
        </v-form>
    </div>
</template>
