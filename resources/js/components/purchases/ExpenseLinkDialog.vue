<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { http } from '@/utils/http';

/**
 * Links one or more expenses to a customer order (or only to a customer, or to nobody),
 * optionally marking their costs "da ribaltare" with a markup.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    expenseIds: { type: Array, required: true },
    /** Current link of a single expense: { order_id, customer_id }. */
    current: { type: Object, default: null },
});

const emit = defineEmits(['linked']);

const orders = ref([]);
const customers = ref([]);
const loading = ref(false);
const target = ref('order');

const form = useForm({ ids: [], order_id: null, customer_id: null, rebill: true, markup_percent: 0 });

const selectedOrder = computed(() => orders.value.find((order) => order.value === form.order_id));

watch(open, async (value) => {
    if (!value) {
        return;
    }

    form.reset();
    form.clearErrors();
    form.ids = [...props.expenseIds];
    form.order_id = props.current?.order_id ?? null;
    form.customer_id = props.current?.customer_id ?? null;
    target.value = props.current?.customer_id && !props.current?.order_id ? 'customer' : 'order';

    if (!orders.value.length) {
        loading.value = true;

        try {
            const data = await http.get(route('purchases.expenses.link-options'));
            orders.value = data.orders;
            customers.value = data.customers;
        } finally {
            loading.value = false;
        }
    }
});

function submit() {
    form.transform((data) => ({
        ...data,
        order_id: target.value === 'order' ? data.order_id : null,
        customer_id: target.value === 'customer' ? data.customer_id : null,
        rebill: target.value !== 'none' && data.rebill,
    })).patch(route('purchases.expenses.link'), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('linked');
        },
    });
}
</script>

<template>
    <v-dialog v-model="open" max-width="560">
        <v-card
            :title="expenseIds.length > 1 ? `Collega ${expenseIds.length} spese` : 'Collega la spesa'"
            subtitle="Per addebitare il costo al cliente con «Fattura costi»"
            :loading="loading"
        >
            <v-card-text>
                <v-btn-toggle v-model="target" mandatory density="compact" color="primary" variant="outlined" class="mb-4 flex-wrap">
                    <v-btn value="order" prepend-icon="mdi-clipboard-text-outline">A un ordine</v-btn>
                    <v-btn value="customer" prepend-icon="mdi-domain">Solo a un cliente</v-btn>
                    <v-btn value="none" prepend-icon="mdi-link-variant-off">Costo interno</v-btn>
                </v-btn-toggle>

                <v-autocomplete
                    v-if="target === 'order'"
                    v-model="form.order_id"
                    :items="orders"
                    item-props
                    label="Ordine cliente"
                    :error-messages="form.errors.order_id"
                    no-data-text="Nessun ordine aperto"
                    autofocus
                />
                <v-autocomplete
                    v-else-if="target === 'customer'"
                    v-model="form.customer_id"
                    :items="customers"
                    item-props
                    label="Cliente"
                    :error-messages="form.errors.customer_id"
                    autofocus
                />
                <div v-else class="text-body-2 text-medium-emphasis mb-2">La spesa resta un tuo costo e non viene proposta per la fatturazione.</div>

                <template v-if="target !== 'none'">
                    <div v-if="selectedOrder" class="text-caption text-medium-emphasis mb-2">Cliente: {{ selectedOrder.subtitle }}</div>
                    <div class="d-flex align-center flex-wrap ga-4">
                        <v-checkbox v-model="form.rebill" label="Segna i costi «da ribaltare»" density="compact" hide-details />
                        <v-text-field
                            v-if="form.rebill"
                            v-model.number="form.markup_percent"
                            label="Ricarico"
                            type="number"
                            min="0"
                            suffix="%"
                            density="compact"
                            hide-details="auto"
                            :error-messages="form.errors.markup_percent"
                            style="max-width: 140px"
                        />
                    </div>
                    <div class="text-caption text-medium-emphasis mt-2">
                        I costi segnati vengono proposti quando fatturi l'ordine; puoi comunque sceglierli e cambiare il ricarico in «Fattura costi».
                    </div>
                </template>

                <v-alert v-if="form.errors.ids" type="error" variant="tonal" density="compact" class="mt-4">{{ form.errors.ids }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-link-variant"
                    :loading="form.processing"
                    :disabled="(target === 'order' && !form.order_id) || (target === 'customer' && !form.customer_id)"
                    @click="submit"
                >
                    {{ target === 'none' ? 'Scollega' : 'Collega' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
