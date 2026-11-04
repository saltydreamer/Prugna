<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import ExpenseLinkDialog from '@/components/purchases/ExpenseLinkDialog.vue';
import CostInvoiceDialog from '@/components/sales/CostInvoiceDialog.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    expense: { type: Object, required: true },
    paymentMethods: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const { can } = usePermissions();
const costDialog = ref(false);
const linkDialog = ref(false);
const unbilledKeys = computed(() => props.expense.lines.filter((line) => line.type === 'item' && !line.rebilled_in).map((line) => `ex-${line.id}`));
const canInvoiceCosts = computed(
    () => can('invoices.manage') && props.expense.customer_id && props.expense.type !== 'credit_note' && unbilledKeys.value.length > 0,
);

const attachmentUrl = computed(() => route('purchases.expenses.attachment', props.expense.id));
const isImage = computed(() => props.expense.attachment_mime?.startsWith('image/'));
const isPdf = computed(() => props.expense.attachment_mime === 'application/pdf');
const quantity = (value) => Number(value).toLocaleString('it-IT', { maximumFractionDigits: 3 });
const title = computed(() => [props.expense.type_label, props.expense.number].filter(Boolean).join(' '));

const paymentDialog = ref(false);
const paymentForm = useForm({ paid_at: null, payment_method: null });

function openPayment() {
    paymentForm.paid_at = new Date().toISOString().slice(0, 10);
    paymentForm.payment_method = props.expense.payment_method;
    paymentDialog.value = true;
}

function savePayment() {
    paymentForm.patch(route('purchases.expenses.payment', props.expense.id), {
        preserveScroll: true,
        onSuccess: () => (paymentDialog.value = false),
    });
}

async function markUnpaid() {
    if (await confirm({ title: 'Segnare come da pagare?', message: 'Il pagamento registrato verrà annullato.', confirmText: 'Segna da pagare' })) {
        router.patch(route('purchases.expenses.payment', props.expense.id), { paid_at: null }, { preserveScroll: true });
    }
}

async function destroy() {
    if (await confirm({ title: 'Eliminare la spesa?', confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('purchases.expenses.destroy', props.expense.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="title" :subtitle="[expense.supplier?.name, expense.title].filter(Boolean).join(' · ')">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('purchases.expenses.index'))">Spese</v-btn>
                <v-btn v-if="expense.has_attachment" variant="tonal" prepend-icon="mdi-file-eye-outline" :href="attachmentUrl" target="_blank">
                    Documento
                </v-btn>
                <v-btn
                    v-if="expense.can?.update"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('purchases.expenses.edit', expense.id))"
                >
                    Modifica
                </v-btn>
                <v-btn
                    v-if="expense.can?.edit_lines && expense.type !== 'credit_note'"
                    variant="tonal"
                    prepend-icon="mdi-link-variant"
                    @click="linkDialog = true"
                >
                    {{ expense.order_id || expense.customer_id ? 'Cambia collegamento' : 'Collega a ordine' }}
                </v-btn>
                <v-btn v-if="canInvoiceCosts" color="primary" variant="tonal" prepend-icon="mdi-cash-plus" @click="costDialog = true">
                    Fattura al cliente
                </v-btn>
                <template v-if="expense.can?.update && expense.type !== 'credit_note'">
                    <v-btn v-if="!expense.is_paid" color="success" variant="flat" prepend-icon="mdi-cash-check" @click="openPayment">
                        Registra pagamento
                    </v-btn>
                </template>
                <v-menu v-if="expense.can?.update || expense.can?.delete">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" variant="text" aria-label="Altre azioni" />
                    </template>
                    <v-list density="compact">
                        <v-list-item
                            v-if="expense.is_paid && expense.can?.update"
                            prepend-icon="mdi-cash-remove"
                            title="Segna da pagare"
                            @click="markUnpaid"
                        />
                        <v-list-item
                            v-if="expense.has_attachment"
                            prepend-icon="mdi-download"
                            title="Scarica documento"
                            :href="route('purchases.expenses.attachment', { expense: expense.id, download: 1 })"
                        />
                        <v-list-item
                            v-if="expense.can?.delete"
                            prepend-icon="mdi-delete-outline"
                            base-color="error"
                            title="Elimina"
                            @click="destroy"
                        />
                    </v-list>
                </v-menu>
            </template>
        </PageHeader>

        <v-alert v-if="expense.is_overdue" type="error" variant="tonal" class="mb-6">
            Scaduta il {{ formatDate(expense.due_date) }}: {{ formatMoney(expense.total) }} da pagare.
        </v-alert>

        <v-row>
            <v-col cols="12" lg="7">
                <v-card class="mb-6">
                    <v-card-text>
                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-chip v-if="expense.type === 'credit_note'" variant="tonal" class="mb-3">Nota di credito</v-chip>
                                <v-chip v-else-if="expense.is_paid" color="success" variant="tonal" prepend-icon="mdi-check" class="mb-3">
                                    Pagata il {{ formatDate(expense.paid_at) }}
                                </v-chip>
                                <v-chip v-else :color="expense.is_overdue ? 'error' : 'warning'" variant="tonal" class="mb-3">
                                    {{ expense.is_overdue ? 'Scaduta' : 'Da pagare' }}
                                </v-chip>
                                <div class="text-body-2">Data {{ formatDate(expense.document_date) }}</div>
                                <div v-if="expense.due_date" class="text-body-2">Scadenza {{ formatDate(expense.due_date) }}</div>
                                <div v-if="expense.payment_method_label" class="text-body-2">Pagamento: {{ expense.payment_method_label }}</div>
                                <div v-if="expense.category_label" class="text-body-2">Categoria: {{ expense.category_label }}</div>
                                <div class="mt-2 text-body-2">
                                    <div v-if="expense.order">
                                        Per l'ordine
                                        <a v-bind="link(route('sales.orders.show', expense.order.id))">{{ expense.order.number }}</a>
                                    </div>
                                    <div v-if="expense.customer">
                                        Costi ribaltabili a
                                        <a v-bind="link(route('registry.organizations.show', expense.customer.id))">{{ expense.customer.name }}</a>
                                    </div>
                                </div>
                                <div v-if="expense.source !== 'manual'" class="text-caption text-medium-emphasis mt-2">
                                    <v-icon :icon="expense.source === 'xml' ? 'mdi-xml' : 'mdi-creation'" size="small" />
                                    {{
                                        expense.source === 'xml'
                                            ? 'Importata da fattura elettronica XML'
                                            : `Letta con l'AI${expense.ai?.model ? ` (${expense.ai.model})` : ''}`
                                    }}
                                </div>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-sheet border rounded="lg" class="pa-3">
                                    <div class="text-overline text-medium-emphasis">Fornitore</div>
                                    <a
                                        v-if="expense.supplier_id"
                                        class="font-weight-bold"
                                        v-bind="link(route('registry.organizations.show', expense.supplier_id))"
                                    >
                                        {{ expense.supplier?.name }}
                                    </a>
                                    <div v-else class="font-weight-bold">{{ expense.supplier?.name ?? '—' }}</div>
                                    <div v-if="expense.supplier?.vat_number" class="text-body-2 text-medium-emphasis">
                                        P.IVA {{ expense.supplier.vat_number }}
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
                                <th class="text-right">Prezzo</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right">Importo</th>
                                <th v-if="expense.customer_id">Ribaltamento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="line in expense.lines" :key="line.id">
                                <tr v-if="line.type !== 'item'">
                                    <td :colspan="expense.customer_id ? 6 : 5" class="font-weight-bold text-primary">{{ line.description }}</td>
                                </tr>
                                <tr v-else>
                                    <td class="py-2 text-pre-line">{{ line.description }}</td>
                                    <td class="text-right text-no-wrap">{{ quantity(line.quantity) }} {{ line.unit }}</td>
                                    <td class="text-right text-no-wrap">{{ formatMoney(line.unit_price) }}</td>
                                    <td class="text-right">{{ line.vat_nature ?? `${line.vat_rate}%` }}</td>
                                    <td class="text-right text-no-wrap font-weight-medium">{{ formatMoney(line.total) }}</td>
                                    <td v-if="expense.customer_id" class="text-no-wrap">
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

                <v-card v-if="expense.notes" title="Note" class="mb-6">
                    <v-card-text class="text-pre-line">{{ expense.notes }}</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="5">
                <QuoteTotalsCard :totals="expense.totals" class="mb-6" />

                <v-card v-if="expense.has_attachment" title="Documento" :subtitle="expense.attachment_name">
                    <v-img v-if="isImage" :src="attachmentUrl" max-height="640" contain />
                    <iframe v-else-if="isPdf" :src="attachmentUrl" title="Documento" style="width: 100%; height: 640px; border: 0" />
                    <v-card-text v-else>
                        <v-btn
                            variant="tonal"
                            prepend-icon="mdi-download"
                            :href="route('purchases.expenses.attachment', { expense: expense.id, download: 1 })"
                        >
                            Scarica
                        </v-btn>
                    </v-card-text>
                </v-card>
                <v-alert v-if="!expense.customer_id && expense.type !== 'credit_note'" type="info" variant="tonal" class="mt-6">
                    Per addebitare questa spesa a un cliente, usa «Collega a ordine».
                </v-alert>
            </v-col>
        </v-row>

        <ExpenseLinkDialog
            v-model="linkDialog"
            :expense-ids="[expense.id]"
            :current="{ order_id: expense.order_id, customer_id: expense.customer_id }"
        />

        <CostInvoiceDialog
            v-if="expense.customer_id"
            v-model="costDialog"
            :organization-id="expense.customer_id"
            :order-id="expense.order_id"
            :preselect="unbilledKeys"
        />

        <v-dialog v-model="paymentDialog" max-width="420">
            <v-card title="Registra pagamento" :subtitle="formatMoney(expense.total)">
                <v-card-text>
                    <v-text-field
                        v-model="paymentForm.paid_at"
                        label="Pagata il"
                        type="date"
                        :error-messages="paymentForm.errors.paid_at"
                        class="mb-2"
                    />
                    <v-select
                        v-model="paymentForm.payment_method"
                        :items="paymentMethods"
                        label="Pagamento"
                        clearable
                        :error-messages="paymentForm.errors.payment_method"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="paymentDialog = false">Annulla</v-btn>
                    <v-btn color="success" variant="flat" :loading="paymentForm.processing" @click="savePayment">Registra</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
