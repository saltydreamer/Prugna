<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import DocumentSendDialog from '@/components/DocumentSendDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';

const props = defineProps({
    purchaseOrder: { type: Object, required: true },
    statuses: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const sendDialog = ref(false);

const quantity = (value) => Number(value).toLocaleString('it-IT', { maximumFractionDigits: 3 });
const rebillLines = computed(() => props.purchaseOrder.lines.filter((line) => line.type === 'item' && line.rebill));
const rebillTotal = computed(() => rebillLines.value.reduce((sum, line) => sum + line.rebill_amount, 0));
const rebillCost = computed(() => rebillLines.value.reduce((sum, line) => sum + line.total, 0));
const pendingRebill = computed(() => rebillLines.value.filter((line) => !line.rebilled_in));
const isCommitted = computed(() => ['sent', 'confirmed', 'received'].includes(props.purchaseOrder.status));

function setStatus(status) {
    router.patch(route('purchases.orders.status', props.purchaseOrder.id), { status }, { preserveScroll: true });
}

async function destroy() {
    if (await confirm({ title: `Eliminare l'ordine ${props.purchaseOrder.number}?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('purchases.orders.destroy', props.purchaseOrder.id));
    }
}
</script>

<template>
    <div>
        <PageHeader
            :title="`Ordine fornitore ${purchaseOrder.number}`"
            :subtitle="[purchaseOrder.supplier.name, purchaseOrder.title].filter(Boolean).join(' · ')"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('purchases.orders.index'))">Ordini fornitore</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-file-pdf-box" :href="route('purchases.orders.pdf', purchaseOrder.id)" target="_blank">
                    PDF
                </v-btn>
                <v-btn
                    v-if="purchaseOrder.can?.update"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('purchases.orders.edit', purchaseOrder.id))"
                >
                    Modifica
                </v-btn>
                <v-btn
                    v-if="purchaseOrder.can?.update && purchaseOrder.status !== 'cancelled'"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-send-outline"
                    @click="sendDialog = true"
                >
                    {{ purchaseOrder.sent_at ? 'Invia di nuovo' : 'Invia al fornitore' }}
                </v-btn>
                <v-menu v-if="purchaseOrder.can?.update || purchaseOrder.can?.delete">
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
                            :active="status.value === purchaseOrder.status"
                            :disabled="!purchaseOrder.can?.update"
                            @click="setStatus(status.value)"
                        />
                        <v-divider class="my-1" />
                        <v-list-item
                            prepend-icon="mdi-download"
                            title="Scarica PDF"
                            :href="route('purchases.orders.pdf', { purchase_order: purchaseOrder.id, download: 1 })"
                        />
                        <v-list-item
                            v-if="purchaseOrder.can?.delete"
                            prepend-icon="mdi-delete-outline"
                            base-color="error"
                            title="Elimina"
                            @click="destroy"
                        />
                    </v-list>
                </v-menu>
            </template>
        </PageHeader>

        <v-alert v-if="purchaseOrder.is_late" type="warning" variant="tonal" class="mb-6">
            La consegna era attesa per il {{ formatDate(purchaseOrder.expected_date) }}.
        </v-alert>
        <v-alert v-if="rebillLines.length && !isCommitted && purchaseOrder.status !== 'cancelled'" type="info" variant="tonal" class="mb-6">
            I costi da ribaltare diventano disponibili in fattura quando l'ordine è inviato al fornitore.
        </v-alert>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-text>
                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-chip :color="purchaseOrder.status_color" :prepend-icon="purchaseOrder.status_icon" variant="tonal" class="mb-3">
                                    {{ purchaseOrder.status_label }}
                                </v-chip>
                                <div class="text-body-2">Data {{ formatDate(purchaseOrder.order_date) }}</div>
                                <div class="text-body-2">
                                    Consegna attesa: {{ purchaseOrder.expected_date ? formatDate(purchaseOrder.expected_date) : 'da concordare' }}
                                </div>
                                <div v-if="purchaseOrder.supplier_reference" class="text-body-2">
                                    Rif. fornitore {{ purchaseOrder.supplier_reference }}
                                </div>
                                <div v-if="purchaseOrder.payment_terms" class="text-body-2">Pagamento: {{ purchaseOrder.payment_terms }}</div>
                                <div v-if="purchaseOrder.sent_at" class="text-body-2 text-medium-emphasis">
                                    Inviato il {{ formatDateTime(purchaseOrder.sent_at) }}
                                </div>
                                <div class="mt-2 text-body-2">
                                    <div v-if="purchaseOrder.order">
                                        Per l'ordine cliente
                                        <a v-bind="link(route('sales.orders.show', purchaseOrder.order.id))">{{ purchaseOrder.order.number }}</a>
                                    </div>
                                    <div v-if="purchaseOrder.customer">
                                        Costi ribaltabili a
                                        <a v-bind="link(route('registry.organizations.show', purchaseOrder.customer.id))">
                                            {{ purchaseOrder.customer.name }}
                                        </a>
                                    </div>
                                    <div v-if="!purchaseOrder.customer" class="text-medium-emphasis">Acquisto interno (nessun cliente)</div>
                                </div>
                                <div v-if="purchaseOrder.owner" class="text-body-2 text-medium-emphasis mt-2">
                                    Responsabile: {{ purchaseOrder.owner.name }}
                                </div>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-sheet border rounded="lg" class="pa-3">
                                    <div class="text-overline text-medium-emphasis">Fornitore</div>
                                    <a class="font-weight-bold" v-bind="link(route('registry.organizations.show', purchaseOrder.supplier_id))">
                                        {{ purchaseOrder.supplier.name }}
                                    </a>
                                    <div v-if="purchaseOrder.supplier.address" class="text-body-2">{{ purchaseOrder.supplier.address }}</div>
                                    <div v-if="purchaseOrder.supplier.city_line" class="text-body-2">{{ purchaseOrder.supplier.city_line }}</div>
                                    <div v-if="purchaseOrder.contact" class="text-body-2 text-medium-emphasis">
                                        Alla c.a. {{ purchaseOrder.contact.full_name }}
                                    </div>
                                </v-sheet>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <v-card class="mb-6">
                    <v-table density="comfortable">
                        <thead>
                            <tr>
                                <th>Descrizione</th>
                                <th class="text-right">Q.tà</th>
                                <th class="text-right">Costo</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right">Importo</th>
                                <th v-if="purchaseOrder.customer_id">Ribaltamento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="line in purchaseOrder.lines" :key="line.id">
                                <tr v-if="line.type !== 'item'">
                                    <td
                                        :colspan="purchaseOrder.customer_id ? 6 : 5"
                                        :class="line.type === 'section' ? 'font-weight-bold text-primary' : 'text-medium-emphasis'"
                                    >
                                        {{ line.description }}
                                    </td>
                                </tr>
                                <tr v-else>
                                    <td class="py-2">
                                        <div class="text-pre-line">{{ line.description }}</div>
                                        <div v-if="line.code" class="text-caption text-medium-emphasis">{{ line.code }}</div>
                                    </td>
                                    <td class="text-right text-no-wrap">{{ quantity(line.quantity) }} {{ line.unit }}</td>
                                    <td class="text-right text-no-wrap">
                                        {{ formatMoney(line.unit_price) }}
                                        <div v-if="line.discount_percent" class="text-caption text-medium-emphasis">
                                            sc. {{ line.discount_percent }}%
                                        </div>
                                    </td>
                                    <td class="text-right">{{ line.vat_nature ?? `${line.vat_rate}%` }}</td>
                                    <td class="text-right text-no-wrap font-weight-medium">{{ formatMoney(line.total) }}</td>
                                    <td v-if="purchaseOrder.customer_id" class="text-no-wrap">
                                        <template v-if="line.rebill">
                                            <div class="text-body-2">
                                                {{ formatMoney(line.rebill_amount) }}
                                                <span v-if="line.markup_percent" class="text-caption text-medium-emphasis">
                                                    (+{{ line.markup_percent }}%)
                                                </span>
                                            </div>
                                            <v-chip
                                                v-if="line.rebilled_in"
                                                size="x-small"
                                                :color="line.rebilled_in.is_draft ? 'warning' : 'success'"
                                                variant="tonal"
                                                v-bind="link(route('sales.invoices.show', line.rebilled_in.id))"
                                            >
                                                {{ line.rebilled_in.is_draft ? 'In bozza' : 'Fatturato' }} · {{ line.rebilled_in.number }}
                                            </v-chip>
                                            <v-chip v-else size="x-small" color="info" variant="tonal">Da fatturare</v-chip>
                                        </template>
                                        <span v-else class="text-caption text-medium-emphasis">Costo interno</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </v-table>
                </v-card>

                <v-card v-if="purchaseOrder.notes" title="Note per il fornitore" class="mb-6">
                    <v-card-text class="text-pre-line">{{ purchaseOrder.notes }}</v-card-text>
                </v-card>
                <v-card v-if="purchaseOrder.internal_notes" title="Note interne" class="mb-6">
                    <v-card-text class="text-pre-line">{{ purchaseOrder.internal_notes }}</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <QuoteTotalsCard :totals="purchaseOrder.totals" class="mb-6" />

                <v-card v-if="rebillLines.length" title="Da ribaltare al cliente">
                    <v-card-text>
                        <div class="d-flex justify-space-between text-body-2">
                            <span>Costo</span>
                            <span>{{ formatMoney(rebillCost) }}</span>
                        </div>
                        <div class="d-flex justify-space-between text-body-2">
                            <span>Ricarico</span>
                            <span>{{ formatMoney(rebillTotal - rebillCost) }}</span>
                        </div>
                        <div class="d-flex justify-space-between font-weight-bold">
                            <span>Da addebitare</span>
                            <span>{{ formatMoney(rebillTotal) }}</span>
                        </div>
                        <div class="text-caption text-medium-emphasis mt-2">
                            {{ pendingRebill.length ? `${pendingRebill.length} righe ancora da fatturare.` : 'Tutti i costi sono in fattura.' }}
                            Si aggiungono dalla fattura del cliente con «Costi da ribaltare».
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <DocumentSendDialog
            v-model="sendDialog"
            :title="`Invia ${purchaseOrder.number} al fornitore`"
            :compose-url="route('purchases.orders.compose', purchaseOrder.id)"
            :send-url="route('purchases.orders.send', purchaseOrder.id)"
            note="Il PDF non riporta clienti né ricarichi. Le risposte del fornitore arrivano al tuo indirizzo."
        />
    </div>
</template>
