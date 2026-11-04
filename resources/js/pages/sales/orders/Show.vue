<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import DocumentLinesTable from '@/components/sales/DocumentLinesTable.vue';
import CostInvoiceDialog from '@/components/sales/CostInvoiceDialog.vue';
import InvoiceDialog from '@/components/sales/InvoiceDialog.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    order: { type: Object, required: true },
    billing: { type: Object, required: true },
    openDeposits: { type: Number, default: 0 },
    /** Supplier orders, costs and margin (null without "purchases.view"). */
    purchasing: { type: Object, default: null },
    statuses: { type: Array, required: true },
    organizationArchived: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const { can } = usePermissions();

const invoiceDialog = ref(false);
const costDialog = ref(false);
const pendingCosts = computed(() =>
    props.purchasing?.rebill_pending_count ? { count: props.purchasing.rebill_pending_count, amount: props.purchasing.rebill_pending_amount } : null,
);
const canInvoice = computed(
    () => can('invoices.manage') && props.order.status !== 'cancelled' && (props.billing.remaining > 0 || Boolean(pendingCosts.value)),
);

function setStatus(status) {
    router.patch(route('sales.orders.status', props.order.id), { status }, { preserveScroll: true });
}

async function destroy() {
    if (await confirm({ title: `Eliminare l'ordine ${props.order.number}?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('sales.orders.destroy', props.order.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="`Ordine ${order.number}`" :subtitle="[order.customer.name, order.title].filter(Boolean).join(' · ')">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('sales.orders.index'))">Ordini</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-file-pdf-box" :href="route('sales.orders.pdf', order.id)" target="_blank">
                    Conferma d'ordine
                </v-btn>
                <v-btn
                    v-if="order.can?.update"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('sales.orders.edit', order.id))"
                >
                    Modifica
                </v-btn>
                <v-btn v-if="canInvoice" color="primary" variant="flat" prepend-icon="mdi-receipt-text-plus-outline" @click="invoiceDialog = true">
                    Fattura
                </v-btn>
                <v-menu v-if="order.can?.update || order.can?.delete">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" variant="text" aria-label="Altre azioni" />
                    </template>
                    <v-list density="compact">
                        <v-list-subheader>Stato</v-list-subheader>
                        <v-list-item
                            v-for="status in statuses"
                            :key="status.value"
                            :prepend-icon="status.icon"
                            :title="status.title"
                            :active="status.value === order.status"
                            :disabled="!order.can?.update"
                            @click="setStatus(status.value)"
                        />
                        <v-divider class="my-1" />
                        <v-list-item
                            prepend-icon="mdi-download"
                            title="Scarica conferma d'ordine"
                            :href="route('sales.orders.pdf', { order: order.id, download: 1 })"
                        />
                        <v-list-item v-if="order.can?.delete" prepend-icon="mdi-delete-outline" base-color="error" title="Elimina" @click="destroy" />
                    </v-list>
                </v-menu>
            </template>
        </PageHeader>

        <v-alert v-if="order.is_late" type="warning" variant="tonal" class="mb-6">
            La consegna era prevista per il {{ formatDate(order.delivery_date) }}.
        </v-alert>
        <v-alert v-if="organizationArchived" type="info" variant="tonal" class="mb-6">Il cliente è stato archiviato.</v-alert>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-text>
                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-chip :color="order.status_color" :prepend-icon="order.status_icon" variant="tonal" class="mb-3">
                                    {{ order.status_label }}
                                </v-chip>
                                <div class="text-body-2">Data ordine {{ formatDate(order.order_date) }}</div>
                                <div class="text-body-2">
                                    Consegna prevista: {{ order.delivery_date ? formatDate(order.delivery_date) : 'da concordare' }}
                                </div>
                                <div v-if="order.customer_reference" class="text-body-2">
                                    Rif. cliente {{ order.customer_reference }}
                                    <template v-if="order.customer_reference_date">del {{ formatDate(order.customer_reference_date) }}</template>
                                </div>
                                <div v-if="order.payment_terms" class="text-body-2">Pagamento: {{ order.payment_terms }}</div>
                                <div v-if="order.quote" class="text-body-2 mt-2">
                                    Da preventivo
                                    <a v-bind="link(route('sales.quotes.show', order.quote.id))">{{ order.quote.number }}</a>
                                </div>
                                <div v-if="order.owner" class="text-body-2 text-medium-emphasis mt-2">Responsabile: {{ order.owner.name }}</div>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-sheet border rounded="lg" class="pa-3">
                                    <div class="text-overline text-medium-emphasis">Cliente</div>
                                    <a class="font-weight-bold" v-bind="link(route('registry.organizations.show', order.organization_id))">
                                        {{ order.customer.name }}
                                    </a>
                                    <div v-if="order.customer.address" class="text-body-2">{{ order.customer.address }}</div>
                                    <div v-if="order.customer.city_line" class="text-body-2">{{ order.customer.city_line }}</div>
                                    <div v-if="order.contact" class="text-body-2 text-medium-emphasis">Alla c.a. {{ order.contact.full_name }}</div>
                                </v-sheet>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <v-card class="mb-6">
                    <DocumentLinesTable :lines="order.lines" progress />
                </v-card>

                <v-card v-if="order.notes" title="Note" class="mb-6">
                    <v-card-text class="text-pre-line">{{ order.notes }}</v-card-text>
                </v-card>
                <v-card v-if="order.internal_notes" title="Note interne" subtitle="Non compaiono nella conferma d'ordine" class="mb-6">
                    <v-card-text class="text-pre-line">{{ order.internal_notes }}</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <v-card class="mb-6" title="Fatturazione">
                    <v-card-text>
                        <v-progress-linear
                            :model-value="billing.percent"
                            :color="billing.percent >= 100 ? 'success' : 'primary'"
                            height="20"
                            rounded
                            class="mb-3"
                        >
                            <strong class="text-caption">{{ Math.round(billing.percent) }}%</strong>
                        </v-progress-linear>
                        <div class="d-flex justify-space-between text-body-2">
                            <span>Fatturato</span>
                            <span>{{ formatMoney(billing.invoiced) }}</span>
                        </div>
                        <div class="d-flex justify-space-between text-body-2 font-weight-bold">
                            <span>Da fatturare</span>
                            <span>{{ formatMoney(billing.remaining) }}</span>
                        </div>
                        <div v-if="billing.deposits_open" class="d-flex justify-space-between text-body-2 text-medium-emphasis">
                            <span>Acconti da detrarre</span>
                            <span>{{ formatMoney(billing.deposits_open) }}</span>
                        </div>
                        <div class="text-caption text-medium-emphasis mt-1">Importi IVA esclusa.</div>
                    </v-card-text>
                    <v-list v-if="order.invoices.length" density="compact" class="pt-0">
                        <v-list-item
                            v-for="invoice in order.invoices"
                            :key="invoice.id"
                            :title="`${invoice.type_label} ${invoice.number}`"
                            :subtitle="`${formatDate(invoice.issue_date)} · ${formatMoney(invoice.subtotal)} + IVA`"
                            :prepend-icon="invoice.status === 'draft' ? 'mdi-file-document-edit-outline' : 'mdi-receipt-text-check-outline'"
                            v-bind="link(route('sales.invoices.show', invoice.id))"
                        >
                            <template v-if="invoice.status === 'draft'" #append>
                                <v-chip size="x-small" variant="tonal">Bozza</v-chip>
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-card-actions v-if="canInvoice">
                        <v-btn block color="primary" variant="tonal" prepend-icon="mdi-receipt-text-plus-outline" @click="invoiceDialog = true">
                            Crea fattura
                        </v-btn>
                    </v-card-actions>
                </v-card>

                <v-card v-if="purchasing" class="mb-6" title="Acquisti e margine">
                    <v-card-text>
                        <div class="d-flex justify-space-between text-body-2">
                            <span>Costi d'acquisto</span>
                            <span>{{ formatMoney(purchasing.costs) }}</span>
                        </div>
                        <div v-if="purchasing.invoiced_costs" class="d-flex justify-space-between text-body-2">
                            <span>Costi fatturati al cliente</span>
                            <span>{{ formatMoney(purchasing.invoiced_costs) }}</span>
                        </div>
                        <div v-if="purchasing.rebill_pending_count" class="d-flex justify-space-between text-body-2">
                            <span>Segnati da ribaltare</span>
                            <span>{{ formatMoney(purchasing.rebill_pending_amount) }}</span>
                        </div>
                        <div v-if="purchasing.available_count" class="d-flex justify-space-between text-body-2 text-medium-emphasis">
                            <span>Costi non ancora fatturati ({{ purchasing.available_count }})</span>
                            <span>{{ formatMoney(purchasing.available_amount) }}</span>
                        </div>
                        <div class="d-flex justify-space-between font-weight-bold" :class="purchasing.margin < 0 ? 'text-error' : 'text-success'">
                            <span>Margine stimato</span>
                            <span>{{ formatMoney(purchasing.margin) }}</span>
                        </div>
                        <div class="text-caption text-medium-emphasis mt-1">
                            Ricavi dell'ordine più i costi fatturati (o segnati da ribaltare) al cliente, meno tutti i costi. IVA esclusa.
                        </div>
                    </v-card-text>
                    <v-list v-if="purchasing.purchase_orders.length || purchasing.expenses.length" density="compact" class="pt-0">
                        <v-list-item
                            v-for="purchaseOrder in purchasing.purchase_orders"
                            :key="purchaseOrder.id"
                            :title="`${purchaseOrder.number} · ${purchaseOrder.supplier}`"
                            :subtitle="`${purchaseOrder.status_label} · ${formatMoney(purchaseOrder.subtotal)} + IVA`"
                            prepend-icon="mdi-cart-outline"
                            v-bind="link(route('purchases.orders.show', purchaseOrder.id))"
                        />
                        <v-list-item
                            v-for="expense in purchasing.expenses"
                            :key="`expense-${expense.id}`"
                            :title="`${expense.label} · ${expense.supplier ?? ''}`"
                            :subtitle="`${formatDate(expense.document_date)} · ${formatMoney(expense.subtotal)} + IVA`"
                            prepend-icon="mdi-receipt-text-outline"
                            v-bind="link(route('purchases.expenses.show', expense.id))"
                        />
                    </v-list>
                    <v-card-actions
                        v-if="(can('purchases.manage') || can('expenses.manage') || can('invoices.manage')) && order.status !== 'cancelled'"
                        class="flex-wrap ga-2"
                    >
                        <v-btn
                            v-if="can('invoices.manage') && purchasing.available_count"
                            block
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-cash-plus"
                            @click="costDialog = true"
                        >
                            Fattura costi al cliente
                        </v-btn>
                        <v-btn
                            v-if="can('purchases.manage')"
                            block
                            variant="tonal"
                            prepend-icon="mdi-cart-plus"
                            v-bind="link(route('purchases.orders.create', { order: order.id }))"
                        >
                            Ordina al fornitore
                        </v-btn>
                        <v-btn
                            v-if="can('expenses.manage')"
                            block
                            variant="tonal"
                            prepend-icon="mdi-receipt-text-plus-outline"
                            v-bind="link(route('purchases.expenses.create', { order: order.id }))"
                        >
                            Registra spesa
                        </v-btn>
                    </v-card-actions>
                </v-card>

                <QuoteTotalsCard :totals="order.totals" />
            </v-col>
        </v-row>

        <CostInvoiceDialog v-model="costDialog" :organization-id="order.organization_id" :order-id="order.id" />

        <InvoiceDialog
            v-model="invoiceDialog"
            source="order"
            :document="order"
            :remaining="billing.remaining"
            :open-deposits="openDeposits"
            :pending-costs="pendingCosts"
        />
    </div>
</template>
