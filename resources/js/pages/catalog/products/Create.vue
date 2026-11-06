<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import ProductForm from '@/components/catalog/ProductForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    types: { type: Array, required: true },
    units: { type: Array, required: true },
    billingPeriods: { type: Array, required: true },
    categories: { type: Array, required: true },
    businessLines: { type: Array, default: () => [] },
    vatRates: { type: Array, required: true },
    nextCodes: { type: Object, required: true },
    defaultVatRateId: { type: Number, default: null },
});

const { link } = useInertiaLink();

const type = new URLSearchParams(window.location.search).get('type') === 'service' ? 'service' : 'product';

const form = useForm({
    type,
    code: '',
    name: '',
    description: '',
    category_id: null,
    business_line_id: null,
    unit: type === 'service' ? 'h' : 'pz',
    price: '',
    cost: '',
    vat_rate_id: props.defaultVatRateId,
    billing_period: null,
    is_active: true,
    notes: '',
});

function submit() {
    form.transform((data) => ({ ...data, cost: data.cost === '' ? null : data.cost })).post(route('catalog.products.store'));
}
</script>

<template>
    <div>
        <PageHeader title="Nuovo articolo">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('catalog.products.index'))">Prodotti e servizi</v-btn>
            </template>
        </PageHeader>

        <v-card>
            <v-form @submit.prevent="submit">
                <v-card-text>
                    <ProductForm
                        v-model:form="form"
                        :types="types"
                        :units="units"
                        :billing-periods="billingPeriods"
                        :categories="categories"
                        :business-lines="businessLines"
                        :vat-rates="vatRates"
                        :next-codes="nextCodes"
                        is-new
                    />
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-spacer />
                    <v-btn variant="text" v-bind="link(route('catalog.products.index'))">Annulla</v-btn>
                    <v-btn type="submit" color="primary" variant="flat" :loading="form.processing">Crea articolo</v-btn>
                </v-card-actions>
            </v-form>
        </v-card>
    </div>
</template>
