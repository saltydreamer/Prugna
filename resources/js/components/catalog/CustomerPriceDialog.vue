<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatMoney } from '@/utils/format';

const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    /** Agreed price to edit, or null to create one. */
    customerPrice: { type: Object, default: null },
    /** Fixed side of the relation: set when opened from a product page... */
    productId: { type: Number, default: null },
    listPrice: { type: Number, default: null },
    /** ...or from an organization page. */
    organizationId: { type: Number, default: null },
    /** Options for the other side, loaded lazily with an Inertia partial reload. */
    options: { type: Array, default: null },
    optionsProp: { type: String, required: true },
});

const form = useForm({
    organization_id: null,
    product_id: null,
    mode: 'discount',
    price: '',
    discount_percent: '',
    valid_until: '',
    notes: '',
});

const isNew = computed(() => !props.customerPrice);
const choosingOrganization = computed(() => props.productId !== null);

const selectedListPrice = computed(() => {
    if (props.customerPrice?.list_price != null) {
        return props.customerPrice.list_price;
    }

    if (props.listPrice !== null) {
        return props.listPrice;
    }

    return props.options?.find((option) => option.value === form.product_id)?.price ?? null;
});

const preview = computed(() => {
    if (form.mode === 'price') {
        return form.price === '' ? null : Number(form.price);
    }

    if (form.discount_percent === '' || selectedListPrice.value === null) {
        return null;
    }

    return selectedListPrice.value * (1 - Number(form.discount_percent) / 100);
});

watch(open, (value) => {
    if (!value) {
        return;
    }

    if (props.options === null) {
        router.reload({ only: [props.optionsProp] });
    }

    const current = props.customerPrice;

    form.clearErrors();
    form.defaults({
        organization_id: current?.organization_id ?? props.organizationId,
        product_id: current?.product_id ?? props.productId,
        mode: current?.mode ?? 'discount',
        price: current?.price ?? '',
        discount_percent: current?.discount_percent ?? '',
        valid_until: current?.valid_until ?? '',
        notes: current?.notes ?? '',
    });
    form.reset();
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    const data = (payload) => ({ ...payload, valid_until: payload.valid_until || null });

    if (isNew.value) {
        form.transform(data).post(route('catalog.customer-prices.store'), options);
    } else {
        form.transform(data).put(route('catalog.customer-prices.update', props.customerPrice.id), options);
    }
}
</script>

<template>
    <v-dialog v-model="open" max-width="560">
        <v-card :title="isNew ? 'Nuovo prezzo concordato' : 'Modifica prezzo concordato'">
            <v-divider />
            <v-card-text>
                <v-form id="customer-price-form" @submit.prevent="submit">
                    <template v-if="isNew">
                        <v-autocomplete
                            v-if="choosingOrganization"
                            v-model="form.organization_id"
                            :items="options ?? []"
                            :loading="options === null"
                            item-props
                            label="Cliente"
                            :error-messages="form.errors.organization_id"
                            class="mb-2"
                        />
                        <v-autocomplete
                            v-else
                            v-model="form.product_id"
                            :items="options ?? []"
                            :loading="options === null"
                            label="Articolo"
                            :error-messages="form.errors.product_id || form.errors.organization_id"
                            class="mb-2"
                        />
                    </template>
                    <div v-else class="mb-4">
                        <div class="text-caption text-medium-emphasis">{{ choosingOrganization ? 'Cliente' : 'Articolo' }}</div>
                        <div class="font-weight-medium">
                            {{
                                choosingOrganization
                                    ? customerPrice.organization?.name
                                    : `${customerPrice.product?.code} · ${customerPrice.product?.name}`
                            }}
                        </div>
                    </div>

                    <v-btn-toggle v-model="form.mode" mandatory color="primary" variant="outlined" divided density="comfortable" class="mb-4">
                        <v-btn value="discount">Sconto %</v-btn>
                        <v-btn value="price">Prezzo fisso</v-btn>
                    </v-btn-toggle>

                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-if="form.mode === 'discount'"
                                v-model="form.discount_percent"
                                label="Sconto sul listino"
                                type="number"
                                min="0"
                                max="100"
                                step="0.5"
                                suffix="%"
                                :error-messages="form.errors.discount_percent"
                            />
                            <v-text-field
                                v-else
                                v-model="form.price"
                                label="Prezzo concordato"
                                type="number"
                                min="0"
                                step="0.01"
                                prefix="€"
                                hint="IVA esclusa"
                                persistent-hint
                                :error-messages="form.errors.price"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model="form.valid_until"
                                label="Valido fino al"
                                type="date"
                                hint="Facoltativo"
                                persistent-hint
                                :error-messages="form.errors.valid_until"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="form.notes"
                                label="Note"
                                placeholder="Es. accordo quadro 2026"
                                :error-messages="form.errors.notes"
                            />
                        </v-col>
                    </v-row>

                    <v-alert v-if="preview !== null" type="info" variant="tonal" density="compact" class="mt-2">
                        Prezzo applicato:
                        <strong>{{ formatMoney(preview) }}</strong>
                        <template v-if="selectedListPrice !== null">(listino {{ formatMoney(selectedListPrice) }})</template>
                    </v-alert>
                </v-form>
            </v-card-text>
            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn type="submit" form="customer-price-form" color="primary" variant="flat" :loading="form.processing">Salva</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
