<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import DocumentLinesTable from '@/components/sales/DocumentLinesTable.vue';
import InvoiceTotalsCard from '@/components/sales/InvoiceTotalsCard.vue';
import SdiCard from '@/components/sdi/SdiCard.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    invoice: { type: Object, required: true },
    totals: { type: Object, required: true },
    /** Field => message: what prevents issuing the draft. */
    problems: { type: [Object, Array], default: () => ({}) },
    fiscal: { type: Object, required: true },
    /** SDI transmissions ({ enabled, environment, transmissions }). */
    sdi: { type: Object, default: () => ({ enabled: false, transmissions: [] }) },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();

const isDraft = computed(() => props.invoice.status === 'draft');
const problemList = computed(() => Object.values(props.problems ?? {}));
const title = computed(() => `${props.invoice.type_label} ${isDraft.value ? '(bozza)' : props.invoice.number}`);
const pensionLabel = computed(() => (props.invoice.pension_type === 'TC22' ? 'Rivalsa INPS' : 'Contributo cassa'));
const allTotals = computed(() => ({ ...props.totals, stamp_duty_amount: props.invoice.stamp_duty_amount }));

async function issue() {
    const confirmed = await confirm({
        title: `Emettere ${props.invoice.type_label.toLowerCase()}?`,
        message: `Verrà assegnato il numero progressivo e il documento non sarà più modificabile (per correggerlo servirà una nota di credito). Data: ${formatDate(props.invoice.issue_date)}.`,
        confirmText: 'Emetti',
    });

    if (confirmed) {
        router.post(route('sales.invoices.issue', props.invoice.id), {}, { preserveScroll: true });
    }
}

async function creditNote() {
    const confirmed = await confirm({
        title: 'Creare una nota di credito?',
        message: "Viene preparata una bozza che storna l'intera fattura: potrai modificarla per uno storno parziale.",
        confirmText: 'Crea bozza',
    });

    if (confirmed) {
        router.post(route('sales.invoices.credit-note', props.invoice.id));
    }
}

async function destroy() {
    if (await confirm({ title: 'Eliminare la bozza?', confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('sales.invoices.destroy', props.invoice.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="title" :subtitle="[invoice.customer.name, invoice.title].filter(Boolean).join(' · ')">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('sales.invoices.index'))">Fatture</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-file-pdf-box" :href="route('sales.invoices.pdf', invoice.id)" target="_blank">PDF</v-btn>
                <v-btn v-if="!isDraft" variant="tonal" prepend-icon="mdi-xml" :href="route('sales.invoices.xml', invoice.id)">XML SdI</v-btn>
                <v-btn
                    v-if="invoice.can?.update"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('sales.invoices.edit', invoice.id))"
                >
                    Modifica
                </v-btn>
                <v-btn
                    v-if="invoice.can?.issue"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-stamper"
                    :disabled="problemList.length > 0"
                    @click="issue"
                >
                    Emetti
                </v-btn>
                <v-menu v-if="invoice.can?.credit_note || invoice.can?.delete">
                    <template #activator="{ props: activator }">
                        <v-btn v-bind="activator" icon="mdi-dots-vertical" variant="text" aria-label="Altre azioni" />
                    </template>
                    <v-list density="compact">
                        <v-list-item
                            v-if="invoice.can?.credit_note"
                            prepend-icon="mdi-file-undo-outline"
                            title="Nota di credito"
                            @click="creditNote"
                        />
                        <v-list-item
                            v-if="invoice.can?.delete"
                            prepend-icon="mdi-delete-outline"
                            base-color="error"
                            title="Elimina bozza"
                            @click="destroy"
                        />
                    </v-list>
                </v-menu>
            </template>
        </PageHeader>

        <v-alert v-if="isDraft && problemList.length" type="warning" variant="tonal" class="mb-6" title="Prima di emettere">
            <ul class="ml-4">
                <li v-for="(problem, index) in problemList" :key="index">{{ problem }}</li>
            </ul>
        </v-alert>
        <v-alert v-else-if="isDraft" type="info" variant="tonal" class="mb-6">
            Bozza pronta: controlla righe e totali, poi premi
            <strong>Emetti</strong>
            per assegnare il numero.
        </v-alert>
        <v-alert
            v-else-if="!sdi.enabled && !sdi.imported && !sdi.transmissions.length"
            type="success"
            variant="tonal"
            class="mb-6"
            icon="mdi-cloud-upload-outline"
        >
            Scarica l'
            <strong>XML SdI</strong>
            e caricalo su «Fatture e corrispettivi» dell'Agenzia delle Entrate (Fatture elettroniche → Trasmissione → Upload file fattura). Il PDF è
            una copia di cortesia per il cliente.
        </v-alert>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-text>
                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-chip :color="invoice.status_color" variant="tonal" class="mb-3">{{ invoice.status_label }}</v-chip>
                                <div class="text-body-2">Data {{ formatDate(invoice.issue_date) }}</div>
                                <div v-if="invoice.due_date" class="text-body-2">Scadenza {{ formatDate(invoice.due_date) }}</div>
                                <div v-if="invoice.payment_terms" class="text-body-2">Pagamento: {{ invoice.payment_terms }}</div>
                                <div v-if="invoice.customer_reference" class="text-body-2">
                                    Vs. ordine {{ invoice.customer_reference }}
                                    <template v-if="invoice.customer_reference_date">del {{ formatDate(invoice.customer_reference_date) }}</template>
                                </div>
                                <div v-if="invoice.cig || invoice.cup" class="text-body-2">
                                    <template v-if="invoice.cig">CIG {{ invoice.cig }}</template>
                                    <template v-if="invoice.cup">CUP {{ invoice.cup }}</template>
                                </div>
                                <div class="mt-2 text-body-2">
                                    <div v-if="invoice.order">
                                        Ordine
                                        <a v-bind="link(route('sales.orders.show', invoice.order.id))">{{ invoice.order.number }}</a>
                                    </div>
                                    <div v-if="invoice.quote">
                                        Preventivo
                                        <a v-bind="link(route('sales.quotes.show', invoice.quote.id))">{{ invoice.quote.number }}</a>
                                    </div>
                                    <div v-if="invoice.related_invoice">
                                        Storno della fattura
                                        <a v-bind="link(route('sales.invoices.show', invoice.related_invoice.id))">
                                            {{ invoice.related_invoice.number }}
                                        </a>
                                        del {{ formatDate(invoice.related_invoice.issue_date) }}
                                    </div>
                                    <div v-for="note in invoice.credit_notes" :key="note.id">
                                        Nota di credito
                                        <a v-bind="link(route('sales.invoices.show', note.id))">{{ note.number }}</a>
                                        ({{ formatMoney(note.total) }})
                                    </div>
                                </div>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-sheet border rounded="lg" class="pa-3">
                                    <div class="text-overline text-medium-emphasis">Cliente</div>
                                    <a class="font-weight-bold" v-bind="link(route('registry.organizations.show', invoice.organization_id))">
                                        {{ invoice.customer.name }}
                                    </a>
                                    <div v-if="invoice.customer.address" class="text-body-2">{{ invoice.customer.address }}</div>
                                    <div v-if="invoice.customer.city_line" class="text-body-2">{{ invoice.customer.city_line }}</div>
                                    <div v-if="invoice.customer.vat_number" class="text-body-2 text-medium-emphasis">
                                        P.IVA {{ invoice.customer.vat_number }}
                                    </div>
                                    <div v-if="invoice.customer.tax_code" class="text-body-2 text-medium-emphasis">
                                        C.F. {{ invoice.customer.tax_code }}
                                    </div>
                                    <div class="text-body-2 text-medium-emphasis">
                                        <template v-if="invoice.customer.sdi_code">Codice destinatario {{ invoice.customer.sdi_code }}</template>
                                        <template v-else-if="invoice.customer.pec">PEC {{ invoice.customer.pec }}</template>
                                        <template v-else>Codice destinatario 0000000</template>
                                    </div>
                                </v-sheet>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <v-card class="mb-6">
                    <DocumentLinesTable :lines="invoice.lines" />
                </v-card>

                <v-card v-if="invoice.notes" title="Note" class="mb-6">
                    <v-card-text class="text-pre-line">{{ invoice.notes }}</v-card-text>
                </v-card>
                <v-card v-if="invoice.internal_notes" title="Note interne" subtitle="Non compaiono in fattura" class="mb-6">
                    <v-card-text class="text-pre-line">{{ invoice.internal_notes }}</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <InvoiceTotalsCard :totals="allTotals" :invoice="invoice" :pension-label="pensionLabel" class="mb-6" />

                <SdiCard v-if="!isDraft" :invoice="invoice" :sdi="sdi" class="mb-6" />

                <v-card title="Dati fiscali">
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-scale-balance" title="Regime" :subtitle="fiscal.regime" />
                        <v-list-item
                            v-if="invoice.apply_withholding"
                            prepend-icon="mdi-percent-outline"
                            :title="`Ritenuta ${invoice.withholding_rate}%`"
                            :subtitle="[fiscal.withholding_type, fiscal.withholding_reason].filter(Boolean).join(' · ')"
                        />
                        <v-list-item
                            v-if="invoice.apply_pension"
                            prepend-icon="mdi-piggy-bank-outline"
                            :title="`${pensionLabel} ${invoice.pension_rate}%`"
                            :subtitle="fiscal.pension_type"
                        />
                        <v-list-item
                            v-if="invoice.split_payment"
                            prepend-icon="mdi-call-split"
                            title="Scissione dei pagamenti"
                            subtitle="L'IVA è versata dal cliente all'Erario"
                        />
                        <v-list-item
                            v-if="invoice.stamp_duty_amount"
                            prepend-icon="mdi-stamper"
                            title="Bollo virtuale"
                            :subtitle="formatMoney(invoice.stamp_duty_amount)"
                        />
                    </v-list>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>
