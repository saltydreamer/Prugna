<script setup>
import { computed, ref, watch } from 'vue';
import { route } from 'ziggy-js';
import { formatDate, formatMoney } from '@/utils/format';
import { http } from '@/utils/http';

/**
 * Supplier costs waiting to be charged back to the customer: the chosen ones are added to the invoice lines.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, default: null },
    /** purchase_order_line_id already in the invoice. */
    exclude: { type: Array, default: () => [] },
});

const emit = defineEmits(['add']);

const costs = ref([]);
const selected = ref([]);
const loading = ref(false);

const total = computed(() =>
    costs.value.filter((cost) => selected.value.includes(cost.purchase_order_line_id)).reduce((sum, cost) => sum + cost.amount, 0),
);

watch(open, async (value) => {
    if (!value || !props.organizationId) {
        return;
    }

    loading.value = true;
    selected.value = [];

    try {
        const query = new URLSearchParams({ organization_id: props.organizationId });
        props.exclude.forEach((id) => query.append('exclude[]', id));
        const { data } = await http.get(`${route('sales.invoices.lookup.costs')}?${query}`);
        costs.value = data;
        selected.value = data.map((cost) => cost.purchase_order_line_id);
    } finally {
        loading.value = false;
    }
});

function add() {
    emit(
        'add',
        costs.value.filter((cost) => selected.value.includes(cost.purchase_order_line_id)),
    );
    open.value = false;
}
</script>

<template>
    <v-dialog v-model="open" max-width="760" scrollable>
        <v-card title="Costi da ribaltare" subtitle="Acquisti fatti per questo cliente e non ancora addebitati" :loading="loading">
            <v-card-text>
                <v-empty-state
                    v-if="!loading && !costs.length"
                    icon="mdi-cart-check"
                    title="Nessun costo in attesa"
                    text="I costi compaiono qui quando un ordine fornitore intestato a questo cliente, con righe «da ribaltare», viene inviato."
                    size="48"
                />
                <v-table v-else density="compact">
                    <thead>
                        <tr>
                            <th style="width: 40px"></th>
                            <th>Costo</th>
                            <th class="text-right">Costo</th>
                            <th class="text-right">Ricarico</th>
                            <th class="text-right">Al cliente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="cost in costs" :key="cost.purchase_order_line_id">
                            <td><v-checkbox-btn v-model="selected" :value="cost.purchase_order_line_id" density="compact" /></td>
                            <td class="py-2">
                                <div class="text-body-2">{{ cost.quantity }} {{ cost.unit }} · {{ cost.description }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ cost.purchase_order.number }} del {{ formatDate(cost.purchase_order.order_date) }} ·
                                    {{ cost.purchase_order.supplier }}
                                </div>
                            </td>
                            <td class="text-right text-no-wrap">{{ formatMoney(cost.cost) }}</td>
                            <td class="text-right">{{ cost.markup_percent ? `${cost.markup_percent}%` : '—' }}</td>
                            <td class="text-right text-no-wrap font-weight-medium">{{ formatMoney(cost.amount) }}</td>
                        </tr>
                    </tbody>
                </v-table>
                <div class="text-caption text-medium-emphasis mt-3">
                    Per le spese anticipate in nome e per conto del cliente (senza ricarico) puoi poi scegliere l'IVA «Escluso art. 15» sulla riga.
                </div>
            </v-card-text>
            <v-card-actions>
                <span v-if="selected.length" class="text-body-2 ml-2">{{ selected.length }} righe · {{ formatMoney(total) }} + IVA</span>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" :disabled="!selected.length" @click="add">Aggiungi alla fattura</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
