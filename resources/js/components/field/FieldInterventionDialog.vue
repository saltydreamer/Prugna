<script setup>
import { computed, reactive, ref, watch } from 'vue';
import SignatureDialog from '@/components/interventions/SignatureDialog.vue';
import { formatHours, formatMinutes, roundUp, startOfHour } from '@/utils/duration';
import { formatMoney } from '@/utils/format';

/**
 * Intervention form of "Interventi sul campo": works offline with the reference data of the page.
 * Emits "save" with the payload for /field/sync and a summary for the list.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    /** Values to start from (timer, or an item to correct). */
    initial: { type: Object, default: null },
    customers: { type: Array, required: true },
    categories: { type: Array, required: true },
    modes: { type: Array, required: true },
    products: { type: Array, required: true },
    rounding: { type: Number, required: true },
    /** Errors from the server for an item to correct. */
    errors: { type: Array, default: () => [] },
});

const emit = defineEmits(['save']);

function toLocalInput(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const blank = () => ({
    organization_id: null,
    location_id: null,
    category_id: props.categories[0]?.value ?? null,
    mode: 'remote',
    // Recorded afterwards: one hour of work, from the full hour before (14:37 → 13:00).
    started_at: toLocalInput(startOfHour(new Date(Date.now() - 3_600_000))),
    hours: 1,
    minutes: 0,
    description: '',
    billable: true,
    callout: false,
    km: 0,
    travel_hours: 0,
    travel_minutes: 0,
    materials: [],
    signature: null,
});

const form = reactive(blank());
const formError = ref('');

watch(open, (value) => {
    if (!value) {
        return;
    }

    formError.value = '';
    Object.assign(form, blank(), props.initial ?? {});
});

const customer = computed(() => props.customers.find((item) => item.value === form.organization_id));
const locations = computed(() => customer.value?.locations ?? []);
const onsite = computed(() => form.mode === 'onsite');
const duration = computed(() => (Number(form.hours) || 0) * 60 + (Number(form.minutes) || 0));
const travel = computed(() => (Number(form.travel_hours) || 0) * 60 + (Number(form.travel_minutes) || 0));

watch(
    () => form.organization_id,
    (value, previous) => {
        if (previous !== undefined && value !== previous && !locations.value.some((location) => location.value === form.location_id)) {
            form.location_id = locations.value[0]?.value ?? null;
        }
    },
);

// --- Materials -------------------------------------------------------------------------
const productSearch = ref('');
const productItems = computed(() => {
    const term = productSearch.value?.toLowerCase().trim();

    if (!term) {
        return props.products.slice(0, 30);
    }

    return props.products.filter((product) => `${product.code} ${product.name}`.toLowerCase().includes(term)).slice(0, 30);
});

function addMaterial(product = null) {
    form.materials.push({
        product_id: product?.id ?? null,
        code: product?.code ?? null,
        description: product?.name ?? '',
        unit: product?.unit ?? 'pz',
        quantity: 1,
        unit_price: null,
        list_price: product?.price ?? null,
    });
    productSearch.value = '';
}

// --- Signature -----------------------------------------------------------------------------
const signatureDialog = ref(false);

function signed(signature) {
    form.signature = signature;
}

function save() {
    formError.value = '';

    if (!form.organization_id || !form.category_id) {
        formError.value = 'Scegli cliente e categoria.';

        return;
    }

    if (duration.value < 1) {
        formError.value = 'Indica la durata.';

        return;
    }

    if (form.materials.some((material) => !material.description?.trim() || !(Number(material.quantity) > 0))) {
        formError.value = 'Ogni materiale deve avere descrizione e quantità.';

        return;
    }

    const data = {
        organization_id: form.organization_id,
        location_id: form.location_id,
        category_id: form.category_id,
        mode: form.mode,
        started_at: new Date(form.started_at).toISOString(),
        duration_minutes: duration.value,
        description: form.description,
        billable: form.billable,
        callout: onsite.value && form.callout,
        km: onsite.value ? Number(form.km) || 0 : 0,
        travel_minutes: onsite.value ? travel.value : 0,
        materials: onsite.value
            ? form.materials.map((material) => ({
                  product_id: material.product_id,
                  code: material.code,
                  description: material.description,
                  unit: material.unit,
                  quantity: Number(material.quantity),
                  unit_price: material.unit_price === null || material.unit_price === '' ? null : Number(material.unit_price),
              }))
            : [],
        signature: form.signature,
    };

    emit('save', {
        data,
        form: JSON.parse(JSON.stringify(form)),
        summary: {
            customer: customer.value?.title,
            category: props.categories.find((item) => item.value === form.category_id)?.title,
            mode: form.mode,
            started_at: data.started_at,
            duration_minutes: duration.value,
            description: form.description,
            signed: Boolean(form.signature),
        },
    });
    open.value = false;
}
</script>

<template>
    <v-dialog v-model="open" max-width="640" :fullscreen="$vuetify.display.xs" scrollable>
        <v-card title="Intervento" subtitle="Si salva sul telefono e si sincronizza quando c'è connessione">
            <v-card-text>
                <v-alert v-if="errors.length" type="error" variant="tonal" class="mb-4">
                    <div v-for="error in errors" :key="error">{{ error }}</div>
                </v-alert>

                <v-autocomplete v-model="form.organization_id" :items="customers" label="Cliente" class="mb-2" />
                <v-select v-if="locations.length" v-model="form.location_id" :items="locations" label="Sede" clearable class="mb-2">
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                    </template>
                </v-select>
                <v-select v-model="form.category_id" :items="categories" label="Categoria" class="mb-2" />
                <v-btn-toggle v-model="form.mode" mandatory color="primary" variant="outlined" divided class="w-100 mb-4">
                    <v-btn v-for="mode in modes" :key="mode.value" :value="mode.value" :prepend-icon="mode.icon" class="flex-grow-1">
                        {{ mode.title }}
                    </v-btn>
                </v-btn-toggle>

                <v-row dense>
                    <v-col cols="12" sm="6">
                        <v-text-field v-model="form.started_at" type="datetime-local" label="Inizio" />
                    </v-col>
                    <v-col cols="6" sm="3">
                        <v-text-field v-model.number="form.hours" type="number" min="0" label="Durata" suffix="h" />
                    </v-col>
                    <v-col cols="6" sm="3">
                        <v-text-field v-model.number="form.minutes" type="number" min="0" max="59" step="5" label="Minuti" suffix="min" />
                    </v-col>
                </v-row>
                <div class="text-caption text-medium-emphasis mt-n2 mb-3">
                    {{ formatMinutes(duration) }} · fatturate {{ formatHours(roundUp(duration, rounding)) }}
                </div>

                <v-textarea v-model="form.description" label="Lavoro svolto" rows="3" auto-grow class="mb-2" />
                <v-switch v-model="form.billable" label="Da fatturare al cliente" class="mb-2" />

                <template v-if="onsite">
                    <v-divider class="mb-4" />
                    <v-switch v-model="form.callout" label="Diritto di uscita" class="mb-2" />
                    <v-row dense>
                        <v-col cols="12" sm="4">
                            <v-text-field v-model.number="form.km" type="number" min="0" step="0.1" label="Km" suffix="km" />
                        </v-col>
                        <v-col cols="6" sm="4">
                            <v-text-field v-model.number="form.travel_hours" type="number" min="0" label="Viaggio" suffix="h" />
                        </v-col>
                        <v-col cols="6" sm="4">
                            <v-text-field v-model.number="form.travel_minutes" type="number" min="0" max="59" step="5" label="Minuti" suffix="min" />
                        </v-col>
                    </v-row>

                    <div class="text-subtitle-2 mt-2 mb-2">Materiali</div>
                    <div v-for="(material, index) in form.materials" :key="index" class="d-flex flex-wrap align-center ga-2 mb-2">
                        <v-text-field v-model="material.description" label="Descrizione" hide-details class="flex-grow-1" style="min-width: 180px" />
                        <v-text-field v-model.number="material.quantity" type="number" label="Q.tà" hide-details style="max-width: 80px" />
                        <v-text-field
                            v-model.number="material.unit_price"
                            type="number"
                            step="0.01"
                            label="Prezzo"
                            prefix="€"
                            hide-details
                            style="max-width: 120px"
                            :placeholder="material.list_price !== null ? 'listino' : ''"
                            persistent-placeholder
                        />
                        <v-btn icon="mdi-delete-outline" size="small" variant="text" title="Rimuovi" @click="form.materials.splice(index, 1)" />
                    </div>
                    <div class="d-flex flex-wrap align-center ga-2">
                        <v-autocomplete
                            v-model:search="productSearch"
                            :items="productItems"
                            item-title="name"
                            return-object
                            no-filter
                            hide-details
                            label="Aggiungi dal listino"
                            prepend-inner-icon="mdi-magnify"
                            class="flex-grow-1"
                            style="min-width: 220px"
                            :model-value="null"
                            @update:model-value="(product) => product && addMaterial(product)"
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps" :subtitle="`${item.code} · ${formatMoney(item.price)} / ${item.unit}`" />
                            </template>
                        </v-autocomplete>
                        <v-btn variant="text" prepend-icon="mdi-plus" @click="addMaterial()">Riga libera</v-btn>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">Senza prezzo vale quello concordato con il cliente (o il listino).</div>
                </template>

                <v-divider class="my-4" />
                <div class="d-flex align-center ga-3">
                    <template v-if="form.signature">
                        <img :src="form.signature.data" alt="Firma" class="signature-thumb" />
                        <div class="flex-grow-1 text-body-2">Firmato da {{ form.signature.name }}</div>
                        <v-btn variant="text" size="small" color="error" @click="form.signature = null">Rimuovi</v-btn>
                    </template>
                    <v-btn v-else variant="tonal" prepend-icon="mdi-draw" @click="signatureDialog = true">Firma del cliente</v-btn>
                </div>

                <v-alert v-if="formError" type="error" variant="tonal" class="mt-4">{{ formError }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn color="primary" prepend-icon="mdi-content-save-outline" @click="save">Salva</v-btn>
            </v-card-actions>
        </v-card>

        <SignatureDialog v-model="signatureDialog" @signed="signed" />
    </v-dialog>
</template>

<style scoped>
.signature-thumb {
    height: 48px;
    max-width: 140px;
    background: #fff;
    border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
    border-radius: 6px;
}
</style>
