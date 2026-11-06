<script setup>
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import ProductForm from '@/components/catalog/ProductForm.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    product: { type: Object, required: true },
    types: { type: Array, required: true },
    units: { type: Array, required: true },
    billingPeriods: { type: Array, required: true },
    categories: { type: Array, required: true },
    businessLines: { type: Array, default: () => [] },
    vatRates: { type: Array, required: true },
    nextCodes: { type: Object, required: true },
});

const { link } = useInertiaLink();

const form = useForm({
    type: props.product.type,
    code: props.product.code,
    name: props.product.name,
    description: props.product.description ?? '',
    category_id: props.product.category_id,
    business_line_id: props.product.business_line_id,
    unit: props.product.unit,
    price: props.product.price,
    cost: props.product.cost ?? '',
    vat_rate_id: props.product.vat_rate_id,
    billing_period: props.product.billing_period,
    is_active: props.product.is_active,
    notes: props.product.notes ?? '',
});

function submit() {
    form.transform((data) => ({ ...data, cost: data.cost === '' ? null : data.cost })).put(route('catalog.products.update', props.product.id));
}
</script>

<template>
    <div>
        <PageHeader :title="`Modifica ${product.code}`" :subtitle="product.name">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('catalog.products.show', product.id))">Scheda</v-btn>
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
                    />
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-spacer />
                    <v-btn variant="text" v-bind="link(route('catalog.products.show', product.id))">Annulla</v-btn>
                    <v-btn type="submit" color="primary" variant="flat" :loading="form.processing" :disabled="!form.isDirty">Salva</v-btn>
                </v-card-actions>
            </v-form>
        </v-card>
    </div>
</template>
