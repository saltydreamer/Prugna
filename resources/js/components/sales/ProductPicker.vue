<script setup>
import { ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { http } from '@/utils/http';
import { formatMoney } from '@/utils/format';

/**
 * Catalog search for the quote editor: returns products with the price that applies to the customer.
 */
const props = defineProps({
    organizationId: { type: Number, default: null },
    /** Supplier orders: products at their purchase cost. */
    atCost: { type: Boolean, default: false },
});

const emit = defineEmits(['pick']);

const search = ref('');
const items = ref([]);
const loading = ref(false);
const selected = ref(null);

let timeout;
let requestId = 0;

async function load() {
    const id = ++requestId;
    loading.value = true;

    try {
        const query = new URLSearchParams({ search: search.value ?? '' });

        if (props.organizationId && !props.atCost) {
            query.set('organization_id', props.organizationId);
        }

        if (props.atCost) {
            query.set('at_cost', '1');
        }

        const { data } = await http.get(`${route('sales.quotes.lookup.products')}?${query}`);

        if (id === requestId) {
            items.value = data;
        }
    } finally {
        if (id === requestId) {
            loading.value = false;
        }
    }
}

watch(search, () => {
    clearTimeout(timeout);
    timeout = setTimeout(load, 250);
});

watch(() => props.organizationId, load);

function pick(product) {
    if (!product) {
        return;
    }

    emit('pick', product);
    selected.value = null;
    search.value = '';
}
</script>

<template>
    <v-autocomplete
        v-model="selected"
        v-model:search="search"
        :items="items"
        :loading="loading"
        item-title="name"
        return-object
        no-filter
        hide-details
        clearable
        prepend-inner-icon="mdi-magnify"
        label="Aggiungi dal listino: cerca per codice o nome"
        no-data-text="Nessun articolo trovato"
        @focus="items.length || load()"
        @update:model-value="pick"
    >
        <template #item="{ props: itemProps, item }">
            <v-list-item v-bind="itemProps" :title="item.name">
                <template #prepend>
                    <v-icon :icon="item.type === 'service' ? 'mdi-account-wrench-outline' : 'mdi-package-variant-closed'" />
                </template>
                <v-list-item-subtitle>
                    {{ item.code }} · {{ atCost ? 'costo ' : '' }}{{ formatMoney(item.price) }} / {{ item.unit }}
                    <template v-if="item.billing_period_per">{{ item.billing_period_per }}</template>
                    <v-chip v-if="!['list', 'cost'].includes(item.price_source)" size="x-small" color="success" variant="tonal" class="ml-1">
                        prezzo cliente
                    </v-chip>
                </v-list-item-subtitle>
            </v-list-item>
        </template>
    </v-autocomplete>
</template>
