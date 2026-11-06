<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import InvoiceDialog from '@/components/sales/InvoiceDialog.vue';
import QuoteSendDialog from '@/components/sales/QuoteSendDialog.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { useToast } from '@/composables/useToast';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';

const props = defineProps({
    quote: { type: Object, required: true },
    publicUrl: { type: String, required: true },
    organizationArchived: { type: Boolean, default: false },
    /** Order created from the quote (null when none or not visible). */
    order: { type: Object, default: null },
    invoices: { type: Array, default: () => [] },
    /** Hour contracts created from the packages of the quote. */
    contracts: { type: Array, default: () => [] },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const { can } = usePermissions();
const toast = useToast();

const status = computed(() => props.quote.status);
const canUpdate = computed(() => props.quote.can?.update);
const sendDialog = ref(false);
const decisionDialog = ref(false);
const decisionForm = useForm({ accepted: true, note: '' });
const invoiceDialog = ref(false);

const isAccepted = computed(() => status.value === 'accepted');
const canCreateOrder = computed(() => isAccepted.value && !props.order && can('orders.manage'));
// Without an order the quote is invoiced directly; once an order exists, invoicing goes through it.
const canInvoice = computed(() => isAccepted.value && !props.order && can('invoices.manage'));

function createOrder() {
    router.post(route('sales.quotes.order', props.quote.id));
}

const options = { preserveScroll: true };

const timeline = computed(() =>
    [
        { at: props.quote.created_at, icon: 'mdi-file-document-plus-outline', color: 'grey', text: 'Creato' },
        props.quote.sent_at && { at: props.quote.sent_at, icon: 'mdi-send-outline', color: 'info', text: 'Inviato al cliente' },
        props.quote.viewed_at && { at: props.quote.viewed_at, icon: 'mdi-eye-outline', color: 'info', text: 'Aperto dal cliente' },
        props.quote.decided_at && {
            at: props.quote.decided_at,
            icon: status.value === 'accepted' ? 'mdi-check-decagram-outline' : 'mdi-close-octagon-outline',
            color: status.value === 'accepted' ? 'success' : 'error',
            text: `${status.value === 'accepted' ? 'Accettato' : 'Rifiutato'}${props.quote.decision_name ? ` da ${props.quote.decision_name}` : ''}`,
            note: props.quote.decision_note,
        },
    ].filter(Boolean),
);

function copyLink() {
    navigator.clipboard?.writeText(props.publicUrl);
    toast.success('Link copiato: puoi inviarlo al cliente anche via WhatsApp o chat.');
}

async function markSent() {
    if (
        await confirm({
            title: 'Segnare come inviato?',
            message: 'Usalo se hai inviato il preventivo in altro modo (es. dalla tua posta).',
            confirmText: 'Segna inviato',
        })
    ) {
        router.post(route('sales.quotes.mark-sent', props.quote.id), {}, options);
    }
}

async function reopen() {
    const confirmed = await confirm({
        title: 'Riaprire come bozza?',
        message: 'Potrai modificarlo e inviarlo di nuovo. Il link già inviato al cliente non permetterà di accettarlo finché non lo reinvii.',
        confirmText: 'Riapri',
    });

    if (confirmed) {
        router.post(route('sales.quotes.reopen', props.quote.id));
    }
}

function openDecision(accepted) {
    decisionForm.reset();
    decisionForm.accepted = accepted;
    decisionDialog.value = true;
}

function saveDecision() {
    decisionForm.post(route('sales.quotes.decide', props.quote.id), { ...options, onSuccess: () => (decisionDialog.value = false) });
}

function duplicate() {
    router.post(route('sales.quotes.duplicate', props.quote.id));
}

async function destroy() {
    if (await confirm({ title: `Eliminare la bozza ${props.quote.number}?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('sales.quotes.destroy', props.quote.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="`Preventivo ${quote.number}`" :subtitle="[quote.customer.name, quote.title].filter(Boolean).join(' · ')">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('sales.quotes.index'))">Preventivi</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-file-pdf-box" :href="route('sales.quotes.pdf', quote.id)" target="_blank">PDF</v-btn>
                <template v-if="isAccepted">
                    <v-btn
                        v-if="order"
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-clipboard-text-outline"
                        v-bind="link(route('sales.orders.show', order.id))"
                    >
                        Ordine {{ order.number }}
                    </v-btn>
                    <v-btn v-if="canCreateOrder" color="primary" variant="tonal" prepend-icon="mdi-clipboard-plus-outline" @click="createOrder">
                        Crea ordine
                    </v-btn>
                    <v-btn
                        v-if="canInvoice"
                        color="primary"
                        variant="flat"
                        prepend-icon="mdi-receipt-text-plus-outline"
                        @click="invoiceDialog = true"
                    >
                        Fattura
                    </v-btn>
                </template>
                <template v-if="canUpdate">
                    <v-btn
                        v-if="status === 'draft'"
                        variant="tonal"
                        color="primary"
                        prepend-icon="mdi-pencil-outline"
                        v-bind="link(route('sales.quotes.edit', quote.id))"
                    >
                        Modifica
                    </v-btn>
                    <v-btn
                        v-if="['draft', 'sent'].includes(status)"
                        color="primary"
                        variant="flat"
                        prepend-icon="mdi-send-outline"
                        @click="sendDialog = true"
                    >
                        {{ status === 'draft' ? 'Invia' : 'Invia di nuovo' }}
                    </v-btn>
                </template>
                <v-menu>
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" variant="text" aria-label="Altre azioni" />
                    </template>
                    <v-list density="compact">
                        <v-list-item v-if="status !== 'draft'" prepend-icon="mdi-link-variant" title="Copia link per il cliente" @click="copyLink" />
                        <v-list-item
                            v-if="status !== 'draft'"
                            prepend-icon="mdi-open-in-new"
                            title="Apri pagina cliente"
                            :href="publicUrl"
                            target="_blank"
                        />
                        <v-list-item
                            prepend-icon="mdi-download"
                            title="Scarica PDF"
                            :href="route('sales.quotes.pdf', { quote: quote.id, download: 1 })"
                        />
                        <template v-if="canUpdate">
                            <v-list-item
                                v-if="status === 'draft'"
                                prepend-icon="mdi-email-check-outline"
                                title="Segna come inviato"
                                @click="markSent"
                            />
                            <v-list-item
                                v-if="['sent', 'expired'].includes(status)"
                                prepend-icon="mdi-check"
                                title="Registra accettazione"
                                @click="openDecision(true)"
                            />
                            <v-list-item
                                v-if="['sent', 'expired'].includes(status)"
                                prepend-icon="mdi-close"
                                title="Registra rifiuto"
                                @click="openDecision(false)"
                            />
                            <v-list-item
                                v-if="['sent', 'rejected', 'expired'].includes(status)"
                                prepend-icon="mdi-file-undo-outline"
                                title="Riapri come bozza"
                                @click="reopen"
                            />
                        </template>
                        <v-list-item v-if="can('quotes.create')" prepend-icon="mdi-content-copy" title="Duplica" @click="duplicate" />
                        <v-list-item
                            v-if="quote.can?.delete"
                            prepend-icon="mdi-delete-outline"
                            base-color="error"
                            title="Elimina bozza"
                            @click="destroy"
                        />
                    </v-list>
                </v-menu>
            </template>
        </PageHeader>

        <v-alert v-if="quote.is_expiring" type="warning" variant="tonal" class="mb-6">
            Il preventivo scade il {{ formatDate(quote.valid_until) }} e non ha ancora una risposta.
        </v-alert>
        <v-alert v-if="organizationArchived" type="info" variant="tonal" class="mb-6">Il cliente è stato archiviato.</v-alert>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-text>
                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-chip :color="quote.status_color" :prepend-icon="quote.status_icon" variant="tonal" class="mb-3">
                                    {{ quote.status_label }}
                                </v-chip>
                                <div class="text-body-2">Data {{ formatDate(quote.issue_date) }}</div>
                                <div class="text-body-2">Valido fino al {{ formatDate(quote.valid_until) }}</div>
                                <div v-if="quote.payment_terms" class="text-body-2">Pagamento: {{ quote.payment_terms }}</div>
                                <div v-if="quote.owner" class="text-body-2 text-medium-emphasis mt-2">Responsabile: {{ quote.owner.name }}</div>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-sheet border rounded="lg" class="pa-3">
                                    <div class="text-overline text-medium-emphasis">Cliente</div>
                                    <a class="font-weight-bold" v-bind="link(route('registry.organizations.show', quote.organization_id))">
                                        {{ quote.customer.name }}
                                    </a>
                                    <div v-if="quote.customer.address" class="text-body-2">{{ quote.customer.address }}</div>
                                    <div v-if="quote.customer.city_line" class="text-body-2">{{ quote.customer.city_line }}</div>
                                    <div v-if="quote.customer.vat_number" class="text-body-2 text-medium-emphasis">
                                        P.IVA {{ quote.customer.vat_number }}
                                    </div>
                                    <div v-if="quote.contact" class="text-body-2 text-medium-emphasis">Alla c.a. {{ quote.contact.full_name }}</div>
                                </v-sheet>
                            </v-col>
                        </v-row>
                        <p v-if="quote.intro" class="text-pre-line mt-4 mb-0">{{ quote.intro }}</p>
                    </v-card-text>
                </v-card>

                <v-card class="mb-6">
                    <v-table density="comfortable">
                        <thead>
                            <tr>
                                <th>Descrizione</th>
                                <th class="text-right">Q.tà</th>
                                <th class="text-right">Prezzo</th>
                                <th class="text-right">Sc.</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right">Importo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="line in quote.lines" :key="line.id">
                                <tr v-if="line.type === 'section'">
                                    <td colspan="6" class="font-weight-bold text-primary bg-surface-light">{{ line.description }}</td>
                                </tr>
                                <tr v-else-if="line.type === 'text'">
                                    <td colspan="6" class="text-pre-line text-medium-emphasis">{{ line.description }}</td>
                                </tr>
                                <tr v-else :class="{ 'text-medium-emphasis': line.is_optional && !line.is_selected }">
                                    <td class="py-2">
                                        <div class="text-pre-line">{{ line.description }}</div>
                                        <div class="text-caption text-medium-emphasis">
                                            <template v-if="line.code">{{ line.code }}</template>
                                            <v-chip v-if="line.is_optional" size="x-small" color="secondary" variant="tonal" class="ml-1">
                                                {{ line.is_selected ? 'Facoltativa · inclusa' : 'Facoltativa' }}
                                            </v-chip>
                                            <v-chip
                                                v-if="line.package_hours"
                                                size="x-small"
                                                color="primary"
                                                variant="tonal"
                                                prepend-icon="mdi-timer-sand"
                                                class="ml-1"
                                            >
                                                Pacchetto {{ line.package_hours }} ore{{
                                                    line.package_months ? ` · ${line.package_months} mesi` : ''
                                                }}
                                            </v-chip>
                                        </div>
                                    </td>
                                    <td class="text-right text-no-wrap">{{ line.quantity }} {{ line.unit }}</td>
                                    <td class="text-right text-no-wrap">{{ formatMoney(line.unit_price) }}</td>
                                    <td class="text-right">{{ line.discount_percent ? `${line.discount_percent}%` : '' }}</td>
                                    <td class="text-right">{{ line.vat_nature ?? `${line.vat_rate}%` }}</td>
                                    <td class="text-right text-no-wrap font-weight-medium">{{ formatMoney(line.total) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </v-table>
                </v-card>

                <v-card v-if="quote.terms" title="Condizioni" class="mb-6">
                    <v-card-text class="text-pre-line">{{ quote.terms }}</v-card-text>
                </v-card>
                <v-card v-if="quote.internal_notes" title="Note interne" subtitle="Non visibili al cliente" class="mb-6">
                    <v-card-text class="text-pre-line">{{ quote.internal_notes }}</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <QuoteTotalsCard :totals="quote.totals" class="mb-6" />

                <v-card v-if="contracts.length" title="Monte ore" subtitle="Creati dai pacchetti all'accettazione" class="mb-6">
                    <v-list density="compact">
                        <v-list-item
                            v-for="contract in contracts"
                            :key="contract.id"
                            prepend-icon="mdi-timer-sand-complete"
                            :title="contract.number"
                            :subtitle="`${contract.title} · ${contract.hours} ore`"
                            v-bind="link(route('interventions.contracts.show', contract.id))"
                        />
                    </v-list>
                </v-card>
                <v-card v-if="invoices.length" title="Fatture" class="mb-6">
                    <v-list density="compact">
                        <v-list-item
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            :title="`${invoice.type_label} ${invoice.number}`"
                            :subtitle="`${formatMoney(invoice.subtotal)} + IVA`"
                            :prepend-icon="invoice.status === 'draft' ? 'mdi-file-document-edit-outline' : 'mdi-receipt-text-check-outline'"
                            v-bind="link(route('sales.invoices.show', invoice.id))"
                        />
                    </v-list>
                </v-card>

                <v-card title="Cronologia">
                    <v-card-text>
                        <v-timeline density="compact" side="end" truncate-line="both">
                            <v-timeline-item v-for="(event, index) in timeline" :key="index" :dot-color="event.color" :icon="event.icon" size="small">
                                <div class="font-weight-medium">{{ event.text }}</div>
                                <div class="text-caption text-medium-emphasis">{{ formatDateTime(event.at) }}</div>
                                <div v-if="event.note" class="text-body-2 text-pre-line mt-1">«{{ event.note }}»</div>
                            </v-timeline-item>
                        </v-timeline>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <QuoteSendDialog v-model="sendDialog" :quote="quote" />
        <InvoiceDialog v-if="canInvoice" v-model="invoiceDialog" source="quote" :document="quote" />

        <v-dialog v-model="decisionDialog" max-width="480">
            <v-card :title="decisionForm.accepted ? 'Registra accettazione' : 'Registra rifiuto'">
                <v-form @submit.prevent="saveDecision">
                    <v-card-text>
                        <p class="mb-4 text-body-2">Usalo quando il cliente ti ha risposto per telefono, email o di persona.</p>
                        <v-textarea v-model="decisionForm.note" label="Note" rows="3" auto-grow :error-messages="decisionForm.errors.note" />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="decisionDialog = false">Annulla</v-btn>
                        <v-btn type="submit" :color="decisionForm.accepted ? 'success' : 'error'" variant="flat" :loading="decisionForm.processing">
                            {{ decisionForm.accepted ? 'Accettato' : 'Rifiutato' }}
                        </v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </div>
</template>
