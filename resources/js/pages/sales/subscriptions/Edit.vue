<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import QuoteLinesEditor from '@/components/sales/QuoteLinesEditor.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatMoney } from '@/utils/format';
import { lineTotal } from '@/utils/quoteTotals';

const props = defineProps({
    subscription: { type: Object, default: null },
    defaults: { type: Object, default: null },
    scheduleEditable: { type: Boolean, required: true },
    customers: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    paymentTerms: { type: Array, required: true },
    units: { type: Array, required: true },
    owners: { type: Array, required: true },
    billingPeriods: { type: Array, required: true },
    billingModes: { type: Array, required: true },
    defaultVatRateId: { type: Number, default: null },
});

const { link } = useInertiaLink();
const isNew = computed(() => !props.subscription);
const source = props.subscription ?? props.defaults;

const form = useForm({
    organization_id: source.organization_id,
    title: source.title ?? '',
    billing_period: source.billing_period,
    billing_mode: source.billing_mode,
    starts_on: source.starts_on,
    ends_on: source.ends_on,
    billed_until: source.billed_until,
    payment_term_id: source.payment_term_id ?? null,
    owner_id: source.owner_id ?? null,
    is_active: source.is_active ?? true,
    notes: source.notes ?? '',
    lines: (source.lines ?? []).map((line) => ({ ...line, key: `line-${line.id}` })),
});

const MONTHS = { monthly: 1, quarterly: 3, semiannual: 6, annual: 12 };

const amount = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));
const yearly = computed(() => (amount.value * 12) / (MONTHS[form.billing_period] ?? 1));
const per = computed(() => props.billingPeriods.find((period) => period.value === form.billing_period)?.title.toLowerCase());

function submit() {
    if (isNew.value) {
        form.post(route('sales.subscriptions.store'));
    } else {
        form.put(route('sales.subscriptions.update', props.subscription.id));
    }
}
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Nuovo contratto servizi' : `Contratto ${subscription.number}`"
            subtitle="Servizi addebitati al cliente a ogni periodo"
        >
            <template #actions>
                <v-btn
                    variant="text"
                    prepend-icon="mdi-arrow-left"
                    v-bind="link(isNew ? route('sales.subscriptions.index') : route('sales.subscriptions.show', subscription.id))"
                >
                    {{ isNew ? 'Contratti' : 'Scheda' }}
                </v-btn>
            </template>
        </PageHeader>

        <v-form @submit.prevent="submit">
            <v-row>
                <v-col cols="12" lg="8">
                    <v-card class="mb-6" title="Contratto">
                        <v-card-text>
                            <v-alert v-if="!scheduleEditable" type="info" variant="tonal" density="compact" class="mb-4">
                                Alcuni canoni sono già in fattura: cliente, decorrenza e periodicità non si cambiano più. Per cambiarli dai una
                                scadenza a questo contratto e creane uno nuovo.
                            </v-alert>
                            <v-row dense>
                                <v-col cols="12">
                                    <v-autocomplete
                                        v-model="form.organization_id"
                                        :items="customers"
                                        label="Cliente"
                                        :disabled="!scheduleEditable"
                                        :error-messages="form.errors.organization_id"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field
                                        v-model="form.title"
                                        label="Descrizione"
                                        placeholder="Es. Hosting e manutenzione sito"
                                        :error-messages="form.errors.title"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-select
                                        v-model="form.billing_period"
                                        :items="billingPeriods"
                                        label="Periodicità del canone"
                                        :disabled="!scheduleEditable"
                                        :error-messages="form.errors.billing_period"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-select
                                        v-model="form.billing_mode"
                                        :items="billingModes"
                                        item-props
                                        label="Fatturazione"
                                        :error-messages="form.errors.billing_mode"
                                    />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-text-field
                                        v-model="form.starts_on"
                                        type="date"
                                        label="Decorrenza"
                                        hint="I periodi partono da qui"
                                        persistent-hint
                                        :disabled="!scheduleEditable"
                                        :error-messages="form.errors.starts_on"
                                    />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-text-field
                                        v-model="form.ends_on"
                                        type="date"
                                        label="Scadenza"
                                        clearable
                                        hint="Vuota: si rinnova da solo"
                                        persistent-hint
                                        :error-messages="form.errors.ends_on"
                                    />
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field
                                        v-model="form.billed_until"
                                        type="date"
                                        label="Già fatturato fino al"
                                        clearable
                                        hint="Canoni fatturati fuori da Prugna (es. Dolibarr)"
                                        persistent-hint
                                        :error-messages="form.errors.billed_until"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Servizi" subtitle="Importi di un canone, IVA esclusa">
                        <v-card-text>
                            <QuoteLinesEditor
                                v-model="form.lines"
                                :vat-rates="vatRates"
                                :units="units"
                                :organization-id="form.organization_id"
                                :errors="form.errors"
                                :default-vat-rate-id="defaultVatRateId"
                                :allow-optional="false"
                                items-only
                            />
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Note">
                        <v-card-text>
                            <v-textarea v-model="form.notes" label="Note interne" rows="3" auto-grow hide-details />
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <v-card class="mb-6" title="Canone">
                        <v-card-text>
                            <div class="d-flex justify-space-between align-baseline">
                                <span class="text-medium-emphasis">Canone {{ per }}</span>
                                <span class="text-h6 font-weight-bold">{{ formatMoney(amount) }}</span>
                            </div>
                            <div class="d-flex justify-space-between text-body-2 mt-1">
                                <span class="text-medium-emphasis">In un anno</span>
                                <span>{{ formatMoney(yearly) }}</span>
                            </div>
                            <div class="text-caption text-medium-emphasis mt-2">IVA esclusa.</div>
                        </v-card-text>
                    </v-card>
                    <v-card class="mb-6">
                        <v-card-text>
                            <v-select
                                v-model="form.payment_term_id"
                                :items="paymentTerms"
                                label="Pagamento"
                                clearable
                                hint="Vuoto: quello del cliente"
                                persistent-hint
                                class="mb-2"
                            />
                            <v-select v-model="form.owner_id" :items="owners" label="Responsabile" clearable class="mb-2" />
                            <v-switch
                                v-model="form.is_active"
                                label="Contratto attivo"
                                color="primary"
                                inset
                                hide-details
                                :hint="form.is_active ? '' : 'Sospeso: i canoni non vengono proposti'"
                            />
                        </v-card-text>
                        <v-card-actions>
                            <v-btn type="submit" color="primary" variant="flat" block size="large" :loading="form.processing">Salva contratto</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-col>
            </v-row>
        </v-form>
    </div>
</template>
