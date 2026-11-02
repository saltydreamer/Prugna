<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import DocumentImportDialog from '@/components/DocumentImportDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import QuickOrganizationDialog from '@/components/registry/QuickOrganizationDialog.vue';
import QuoteLinesEditor from '@/components/sales/QuoteLinesEditor.vue';
import QuoteTotalsCard from '@/components/sales/QuoteTotalsCard.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utils/format';
import { quoteTotals } from '@/utils/quoteTotals';

const props = defineProps({
    /** null when creating. */
    expense: { type: Object, default: null },
    defaults: { type: Object, default: null },
    /** What was read from the document (AI or XML): warnings, supplier to create, original file. */
    review: { type: Object, default: null },
    suppliers: { type: Array, required: true },
    customers: { type: Array, required: true },
    orders: { type: Array, default: () => [] },
    vatRates: { type: Array, required: true },
    paymentTerms: { type: Array, default: () => [] },
    paymentMethods: { type: Array, required: true },
    units: { type: Array, required: true },
    types: { type: Array, required: true },
    categories: { type: Array, required: true },
    organizationTypes: { type: Array, default: () => [] },
    canUseAi: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const isNew = computed(() => !props.expense);
const source = props.expense ?? props.defaults;
const linesEditable = computed(() => isNew.value || Boolean(props.expense.can?.edit_lines));

const form = useForm({
    type: source.type ?? 'invoice',
    category: source.category ?? null,
    supplier_id: source.supplier_id ?? null,
    number: source.number ?? '',
    document_date: source.document_date,
    title: source.title ?? '',
    customer_id: source.customer_id ?? null,
    order_id: source.order_id ?? null,
    payment_method: source.payment_method ?? null,
    due_date: source.due_date ?? null,
    paid_at: source.paid_at ?? null,
    notes: source.notes ?? '',
    attachment: null,
    ai_extraction_id: source.ai_extraction_id ?? null,
    lines: (source.lines ?? []).map((line, index) => ({ rebill: false, markup_percent: 0, ...line, key: `line-${line.id ?? index}` })),
});

const isPaid = ref(Boolean(form.paid_at));
watch(isPaid, (value) => (form.paid_at = value ? form.paid_at || form.document_date : null));

const defaultVatRateId = computed(() => props.vatRates.find((rate) => rate.is_default)?.id ?? props.vatRates[0]?.id ?? null);
const ratesById = computed(() => Object.fromEntries(props.vatRates.map((rate) => [rate.id, rate])));
const totals = computed(() =>
    quoteTotals(
        form.lines.map((line) => ({
            ...line,
            vat_rate: ratesById.value[line.vat_rate_id]?.rate ?? line.vat_rate ?? 0,
            vat_nature: ratesById.value[line.vat_rate_id]?.nature ?? line.vat_nature ?? null,
        })),
    ),
);
const totalMismatch = computed(() => props.review?.document_total != null && Math.abs(totals.value.total - props.review.document_total) > 0.02);

watch(
    () => form.order_id,
    (orderId) => {
        const order = props.orders.find((item) => item.value === orderId);

        if (order) {
            form.customer_id = order.organization_id;
        }
    },
);

// --- Supplier ------------------------------------------------------------------------
const supplierDialog = ref(false);
const supplierSearch = ref('');
const supplierInitial = ref(null);
const importDialog = ref(false);

function createSupplier(initial = null) {
    supplierInitial.value = initial;
    supplierDialog.value = true;
}

function supplierCreated(organization) {
    form.supplier_id = organization.value;
    supplierSearch.value = '';
}

const showOriginal = computed(() => props.review?.file_url && /^(image\/|application\/pdf)/.test(props.review.file_mime ?? ''));

function submit() {
    // eslint-disable-next-line no-unused-vars
    const clean = ({ key, id, total, vat_rate, vat_nature, is_optional, is_selected, rebill_amount, rebilled_in, ...line }) => line;
    const transform = (data) => ({
        ...data,
        lines: linesEditable.value ? data.lines.map(clean) : undefined,
        attachment: data.attachment ?? undefined,
        ...(isNew.value ? {} : { _method: 'put' }),
    });

    if (isNew.value) {
        form.transform(transform).post(route('purchases.expenses.store'), { forceFormData: true });
    } else {
        form.transform(transform).post(route('purchases.expenses.update', props.expense.id), { forceFormData: true });
    }
}

const backUrl = computed(() => (isNew.value ? route('purchases.expenses.index') : route('purchases.expenses.show', props.expense.id)));
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Nuova spesa' : 'Modifica spesa'"
            :subtitle="isNew ? 'Fattura del fornitore, ricevuta o scontrino' : expense.supplier?.name"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(backUrl)">{{ isNew ? 'Spese' : 'Scheda' }}</v-btn>
                <v-btn v-if="isNew && !review" variant="tonal" prepend-icon="mdi-file-upload-outline" @click="importDialog = true">
                    Compila da documento
                </v-btn>
            </template>
        </PageHeader>

        <v-alert
            v-if="review"
            :type="review.warnings.length || totalMismatch ? 'warning' : 'success'"
            variant="tonal"
            class="mb-6"
            :title="review.source === 'xml' ? 'Dati importati dalla fattura elettronica' : 'Dati letti dal documento con l\'AI'"
        >
            <div class="text-body-2">
                Controlla i campi prima di salvare.
                <template v-if="review.source === 'ai' && review.model">
                    Modello {{ review.model }}
                    <template v-if="review.cost !== null">· costo {{ `$ ${review.cost.toFixed(4)}` }}</template>
                    .
                </template>
            </div>
            <ul v-if="review.warnings.length" class="ml-4 mt-2 text-body-2">
                <li v-for="(warning, index) in review.warnings" :key="index">{{ warning }}</li>
            </ul>
        </v-alert>

        <v-form @submit.prevent="submit">
            <v-row>
                <v-col cols="12" :lg="showOriginal ? 7 : 8">
                    <v-card class="mb-6" title="Documento">
                        <v-card-text>
                            <v-btn-toggle v-model="form.type" mandatory density="compact" color="primary" variant="outlined" class="mb-4 flex-wrap">
                                <v-btn v-for="type in types" :key="type.value" :value="type.value">{{ type.title }}</v-btn>
                            </v-btn-toggle>

                            <v-alert
                                v-if="review?.supplier_suggestion && !form.supplier_id"
                                type="info"
                                variant="tonal"
                                density="compact"
                                class="mb-4"
                            >
                                <div class="d-flex flex-wrap align-center ga-2">
                                    <span>
                                        <strong>{{ review.supplier_suggestion.name }}</strong>
                                        <template v-if="review.supplier_suggestion.vat_number">
                                            (P.IVA {{ review.supplier_suggestion.vat_number }})
                                        </template>
                                        non è tra i fornitori.
                                    </span>
                                    <v-spacer />
                                    <v-btn
                                        v-if="can('suppliers.create')"
                                        size="small"
                                        color="primary"
                                        variant="flat"
                                        prepend-icon="mdi-truck-plus-outline"
                                        @click="createSupplier(review.supplier_suggestion)"
                                    >
                                        Crea fornitore
                                    </v-btn>
                                </div>
                            </v-alert>

                            <v-row dense>
                                <v-col cols="12" md="8">
                                    <v-autocomplete
                                        v-model="form.supplier_id"
                                        v-model:search="supplierSearch"
                                        :items="suppliers"
                                        item-props
                                        label="Fornitore"
                                        :disabled="!linesEditable"
                                        :error-messages="form.errors.supplier_id"
                                    >
                                        <template v-if="can('suppliers.create') && linesEditable" #append>
                                            <v-btn
                                                icon="mdi-truck-plus-outline"
                                                variant="tonal"
                                                color="primary"
                                                title="Nuovo fornitore"
                                                @click="createSupplier()"
                                            />
                                        </template>
                                    </v-autocomplete>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-select
                                        v-model="form.category"
                                        :items="categories"
                                        label="Categoria"
                                        clearable
                                        :error-messages="form.errors.category"
                                    />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-text-field v-model="form.number" label="Numero documento" :error-messages="form.errors.number" />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-text-field v-model="form.document_date" label="Data" type="date" :error-messages="form.errors.document_date" />
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field v-model="form.title" label="Descrizione" :error-messages="form.errors.title" />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Righe" subtitle="Importi IVA esclusa">
                        <v-card-text>
                            <v-alert v-if="!linesEditable" type="info" variant="tonal">
                                Alcuni costi sono già in fattura al cliente: fornitore, cliente e righe non si modificano più.
                            </v-alert>
                            <QuoteLinesEditor
                                v-else
                                v-model="form.lines"
                                :vat-rates="vatRates"
                                :units="units"
                                :errors="form.errors"
                                :default-vat-rate-id="defaultVatRateId"
                                :allow-optional="false"
                                at-cost
                                :rebillable="Boolean(form.customer_id)"
                            />
                        </v-card-text>
                    </v-card>

                    <v-card
                        class="mb-6"
                        title="Ribalta al cliente"
                        subtitle="Facoltativo: collega un ordine o un cliente per addebitargli questi costi"
                    >
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-autocomplete
                                        v-model="form.order_id"
                                        :items="orders"
                                        item-props
                                        label="Ordine cliente"
                                        clearable
                                        :disabled="!linesEditable"
                                        :error-messages="form.errors.order_id"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-autocomplete
                                        v-model="form.customer_id"
                                        :items="customers"
                                        item-props
                                        label="Cliente"
                                        clearable
                                        :disabled="!linesEditable || Boolean(form.order_id)"
                                        :error-messages="form.errors.customer_id"
                                    />
                                </v-col>
                            </v-row>
                            <div v-if="form.customer_id" class="text-caption text-medium-emphasis">
                                Spunta «Da ribaltare al cliente» sulle righe da addebitare: le trovi poi in fattura con «Costi da ribaltare».
                            </div>
                        </v-card-text>
                    </v-card>

                    <v-card title="Note e allegato">
                        <v-card-text>
                            <v-textarea v-model="form.notes" label="Note" rows="2" auto-grow :error-messages="form.errors.notes" class="mb-4" />
                            <v-file-input
                                v-if="!review"
                                v-model="form.attachment"
                                :label="expense?.has_attachment ? 'Sostituisci documento allegato' : 'Allega documento (foto, PDF o XML)'"
                                accept="image/*,application/pdf,.xml,.p7m"
                                prepend-icon=""
                                prepend-inner-icon="mdi-paperclip"
                                show-size
                                :error-messages="form.errors.attachment"
                            />
                            <div v-else class="text-body-2 text-medium-emphasis">
                                <v-icon icon="mdi-paperclip" size="small" />
                                {{ review.file_name }} verrà allegato alla spesa.
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" :lg="showOriginal ? 5 : 4">
                    <div class="document-sidebar">
                        <QuoteTotalsCard v-if="linesEditable" :totals="totals" class="mb-2" />
                        <div v-if="totalMismatch" class="text-body-2 text-warning mb-4 px-2">
                            Il documento riporta {{ formatMoney(review.document_total) }}: differenza di
                            {{ formatMoney(totals.total - review.document_total) }}.
                        </div>

                        <v-card class="mb-6 mt-4" title="Pagamento">
                            <v-card-text>
                                <v-select
                                    v-model="form.payment_method"
                                    :items="paymentMethods"
                                    label="Modalità"
                                    clearable
                                    :error-messages="form.errors.payment_method"
                                    class="mb-2"
                                />
                                <v-text-field
                                    v-if="!isPaid"
                                    v-model="form.due_date"
                                    label="Scadenza"
                                    type="date"
                                    clearable
                                    :error-messages="form.errors.due_date"
                                    class="mb-2"
                                />
                                <v-switch v-model="isPaid" label="Già pagata" color="success" hide-details :disabled="form.type === 'credit_note'" />
                                <v-text-field
                                    v-if="isPaid"
                                    v-model="form.paid_at"
                                    label="Pagata il"
                                    type="date"
                                    :error-messages="form.errors.paid_at"
                                    class="mt-2"
                                />
                            </v-card-text>
                        </v-card>

                        <div class="d-flex ga-2 mb-6">
                            <v-btn variant="text" v-bind="link(backUrl)">Annulla</v-btn>
                            <v-spacer />
                            <v-btn
                                type="submit"
                                color="primary"
                                variant="flat"
                                size="large"
                                prepend-icon="mdi-content-save-outline"
                                :loading="form.processing"
                            >
                                {{ isNew ? 'Registra spesa' : 'Salva' }}
                            </v-btn>
                        </div>
                        <div v-if="Object.keys(form.errors).length" class="text-error text-body-2 mb-4">Controlla i campi evidenziati.</div>

                        <v-card v-if="showOriginal" title="Documento originale" :subtitle="review.file_name">
                            <v-img v-if="review.file_mime.startsWith('image/')" :src="review.file_url" max-height="700" contain />
                            <iframe v-else :src="review.file_url" title="Documento" style="width: 100%; height: 700px; border: 0" />
                        </v-card>
                    </div>
                </v-col>
            </v-row>
        </v-form>

        <QuickOrganizationDialog
            v-model="supplierDialog"
            :types="organizationTypes"
            :payment-terms="paymentTerms"
            :initial="supplierInitial"
            role="supplier"
            @created="supplierCreated"
        />
        <DocumentImportDialog v-model="importDialog" kind="expense" />
    </div>
</template>

<style scoped>
@media (min-width: 1280px) {
    .document-sidebar {
        position: sticky;
        top: 88px;
    }
}
</style>
