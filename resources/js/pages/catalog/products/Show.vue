<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import CustomerPriceList from '@/components/catalog/CustomerPriceList.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime, formatMoney, formatPercent } from '@/utils/format';

const props = defineProps({
    product: { type: Object, required: true },
    customerPrices: { type: Array, required: true },
    organizationOptions: { type: Array, default: null },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const { can } = usePermissions();

const vat = computed(() => {
    const rate = props.product.vat_rate;

    return rate ? (rate.nature ? `${rate.name} (${rate.nature})` : rate.name) : '—';
});

const priceWithVat = computed(() => props.product.price * (1 + (props.product.vat_rate?.rate ?? 0) / 100));

async function destroy() {
    const confirmed = await confirm({
        title: `Eliminare ${props.product.code}?`,
        message: "Se l'articolo è già stato usato, valuta di disattivarlo invece di eliminarlo.",
        confirmText: 'Elimina',
        color: 'error',
    });

    if (confirmed) {
        router.delete(route('catalog.products.destroy', props.product.id));
    }
}

function duplicate() {
    router.post(route('catalog.products.duplicate', props.product.id));
}
</script>

<template>
    <div>
        <PageHeader
            :title="product.name"
            :subtitle="`${product.code} · ${product.type_label}${product.billing_period_label ? ` · canone ${product.billing_period_label.toLowerCase()}` : ''}`"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('catalog.products.index'))">Prodotti e servizi</v-btn>
                <v-btn
                    v-if="product.can?.update"
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('catalog.products.edit', product.id))"
                >
                    Modifica
                </v-btn>
                <v-menu v-if="can('products.create') || product.can?.delete">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" variant="text" aria-label="Altre azioni" />
                    </template>
                    <v-list density="compact">
                        <v-list-item v-if="can('products.create')" prepend-icon="mdi-content-copy" title="Duplica" @click="duplicate" />
                        <v-list-item
                            v-if="product.can?.delete"
                            prepend-icon="mdi-delete-outline"
                            base-color="error"
                            title="Elimina"
                            @click="destroy"
                        />
                    </v-list>
                </v-menu>
            </template>
        </PageHeader>

        <v-alert v-if="!product.is_active" type="warning" variant="tonal" class="mb-6">
            Articolo disattivato: non viene proposto nei nuovi documenti.
        </v-alert>

        <v-row>
            <v-col cols="12" md="5">
                <v-card class="h-100">
                    <v-card-item>
                        <template #prepend>
                            <v-avatar color="primary" variant="tonal">
                                <v-icon :icon="product.type === 'service' ? 'mdi-account-wrench-outline' : 'mdi-package-variant-closed'" />
                            </v-avatar>
                        </template>
                        <v-card-title class="text-h5 font-weight-bold">{{ formatMoney(product.price) }}</v-card-title>
                        <v-card-subtitle>IVA esclusa, {{ product.billing_period_per ?? `per ${product.unit}` }}</v-card-subtitle>
                    </v-card-item>
                    <v-list density="compact">
                        <v-list-item title="Prezzo IVA inclusa" :subtitle="formatMoney(priceWithVat)" />
                        <v-list-item title="IVA" :subtitle="vat" />
                        <v-list-item title="Costo di acquisto" :subtitle="formatMoney(product.cost)" />
                        <v-list-item title="Margine">
                            <v-list-item-subtitle v-if="product.margin" :class="product.margin.amount < 0 ? 'text-error' : 'text-success'">
                                {{ formatMoney(product.margin.amount) }} · {{ formatPercent(product.margin.percent) }}
                            </v-list-item-subtitle>
                            <v-list-item-subtitle v-else>—</v-list-item-subtitle>
                        </v-list-item>
                        <v-list-item
                            v-if="product.vat_rate?.legal_reference"
                            title="Riferimento normativo"
                            :subtitle="product.vat_rate.legal_reference"
                        />
                    </v-list>
                </v-card>
            </v-col>

            <v-col cols="12" md="7">
                <v-card class="h-100" title="Dettagli">
                    <v-list density="compact">
                        <v-list-item title="Categoria">
                            <v-list-item-subtitle>
                                <v-chip v-if="product.category" :color="product.category.color" variant="tonal">{{ product.category.name }}</v-chip>
                                <span v-else>—</span>
                            </v-list-item-subtitle>
                        </v-list-item>
                        <v-list-item title="Unità di misura" :subtitle="product.unit" />
                        <v-list-item title="Descrizione">
                            <div class="text-body-2 text-medium-emphasis text-pre-line">{{ product.description || '—' }}</div>
                        </v-list-item>
                        <v-list-item v-if="product.notes" title="Note interne">
                            <div class="text-body-2 text-medium-emphasis text-pre-line">{{ product.notes }}</div>
                        </v-list-item>
                    </v-list>
                    <v-card-text class="text-caption text-medium-emphasis pt-0">
                        Creato il {{ formatDateTime(product.created_at) }} · ultima modifica {{ formatDateTime(product.updated_at) }}
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12">
                <CustomerPriceList
                    :prices="customerPrices"
                    context="product"
                    :product-id="product.id"
                    :list-price="product.price"
                    :options="organizationOptions"
                    options-prop="organizationOptions"
                    :can-edit="product.can?.update"
                />
            </v-col>
        </v-row>
    </div>
</template>
