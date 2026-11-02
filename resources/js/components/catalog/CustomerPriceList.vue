<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import CustomerPriceDialog from './CustomerPriceDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatMoney, formatPercent } from '@/utils/format';

/**
 * Prices agreed with customers, shown on the product page (one row per customer)
 * or on the organization page (one row per product).
 */
const props = defineProps({
    prices: { type: Array, required: true },
    /** "product" on the product page, "organization" on the organization page. */
    context: { type: String, required: true },
    productId: { type: Number, default: null },
    listPrice: { type: Number, default: null },
    organizationId: { type: Number, default: null },
    options: { type: Array, default: null },
    optionsProp: { type: String, required: true },
    canEdit: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();

const dialog = ref(false);
const editing = ref(null);

function openDialog(price = null) {
    editing.value = price;
    dialog.value = true;
}

/**
 * "al mese · sconto 20% sul listino di € 150,00 · valido fino al 31 dic 2026 · note"
 */
function describe(price) {
    const parts = [];

    if (price.product?.billing_period_per) {
        parts.push(price.product.billing_period_per);
    }

    if (price.mode === 'discount') {
        parts.push(`sconto ${formatPercent(price.discount_percent, 2)} sul listino di ${formatMoney(price.list_price)}`);
    } else if (price.list_price !== null) {
        parts.push(`prezzo fisso (listino ${formatMoney(price.list_price)})`);
    }

    if (price.valid_until) {
        parts.push(`${price.is_expired ? 'scaduto il' : 'valido fino al'} ${formatDate(price.valid_until)}`);
    }

    if (price.notes) {
        parts.push(price.notes);
    }

    return parts.length ? `· ${parts.join(' · ')}` : '';
}

async function destroy(price) {
    const name = props.context === 'product' ? price.organization?.name : price.product?.name;

    if (
        await confirm({
            title: `Eliminare il prezzo concordato con ${name}?`,
            message: 'Verrà applicato il prezzo di listino.',
            confirmText: 'Elimina',
            color: 'error',
        })
    ) {
        router.delete(route('catalog.customer-prices.destroy', price.id), { preserveScroll: true });
    }
}
</script>

<template>
    <v-card>
        <v-card-item>
            <v-card-title>Prezzi concordati</v-card-title>
            <v-card-subtitle class="text-wrap">
                {{ context === 'product' ? 'Prezzi speciali o sconti per singoli clienti.' : 'Prezzi speciali o sconti per questo cliente.' }}
            </v-card-subtitle>
            <template v-if="canEdit" #append>
                <v-btn color="primary" variant="tonal" prepend-icon="mdi-tag-plus-outline" @click="openDialog()">Aggiungi</v-btn>
            </template>
        </v-card-item>
        <v-divider />

        <v-list v-if="prices.length" lines="two" class="py-0">
            <template v-for="(price, index) in prices" :key="price.id">
                <v-divider v-if="index > 0" />
                <v-list-item :class="{ 'text-disabled': price.is_expired }">
                    <v-list-item-title class="font-weight-medium">
                        <a
                            v-if="context === 'product'"
                            v-bind="link(route('registry.organizations.show', { organization: price.organization_id, tab: 'prices' }))"
                        >
                            {{ price.organization?.name }}
                        </a>
                        <a v-else v-bind="link(route('catalog.products.show', price.product_id))">
                            {{ price.product?.code }} · {{ price.product?.name }}
                        </a>
                    </v-list-item-title>
                    <v-list-item-subtitle>
                        <strong>{{ formatMoney(price.effective_price) }}</strong>
                        {{ describe(price) }}
                    </v-list-item-subtitle>
                    <template v-if="canEdit" #append>
                        <v-btn icon="mdi-pencil-outline" variant="text" size="small" title="Modifica" @click="openDialog(price)" />
                        <v-btn icon="mdi-delete-outline" variant="text" size="small" color="error" title="Elimina" @click="destroy(price)" />
                    </template>
                </v-list-item>
            </template>
        </v-list>
        <v-card-text v-else class="text-medium-emphasis">Nessun prezzo concordato: si applica il listino.</v-card-text>

        <CustomerPriceDialog
            v-model="dialog"
            :customer-price="editing"
            :product-id="productId"
            :list-price="listPrice"
            :organization-id="organizationId"
            :options="options"
            :options-prop="optionsProp"
        />
    </v-card>
</template>
