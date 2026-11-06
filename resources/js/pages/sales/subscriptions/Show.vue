<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import SubscriptionInvoiceDialog from '@/components/sales/SubscriptionInvoiceDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    subscription: { type: Object, required: true },
    /** Next fees to invoice. */
    upcoming: { type: Array, required: true },
    canInvoice: { type: Boolean, required: true },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const generating = ref(false);

const quantity = (value) => Number(value).toLocaleString('it-IT', { maximumFractionDigits: 3 });

async function destroy() {
    const ok = await confirm({ title: `Eliminare il contratto ${props.subscription.number}?`, confirmText: 'Elimina', color: 'error' });

    if (ok) {
        router.delete(route('sales.subscriptions.destroy', props.subscription.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="subscription.title" :subtitle="`${subscription.number} · ${subscription.organization?.name}`">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('sales.subscriptions.index'))">Contratti</v-btn>
                <v-btn
                    v-if="canInvoice && upcoming.length"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-receipt-text-clock-outline"
                    @click="generating = true"
                >
                    Fattura i canoni
                </v-btn>
                <v-btn
                    v-if="subscription.can.update"
                    variant="tonal"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('sales.subscriptions.edit', subscription.id))"
                >
                    Modifica
                </v-btn>
                <v-btn v-if="subscription.can.delete" variant="text" color="error" icon="mdi-delete-outline" title="Elimina" @click="destroy" />
            </template>
        </PageHeader>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-item>
                        <v-card-title class="d-flex align-center ga-2">
                            Servizi
                            <v-chip size="small" :color="subscription.status_color" variant="tonal">{{ subscription.status_label }}</v-chip>
                        </v-card-title>
                    </v-card-item>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Descrizione</th>
                                <th class="text-right">Q.tà</th>
                                <th class="text-right">Prezzo</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right">Importo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in subscription.lines" :key="line.id">
                                <td class="py-2" style="white-space: pre-line">
                                    {{ line.description }}
                                    <div v-if="line.code" class="text-caption text-medium-emphasis">{{ line.code }}</div>
                                </td>
                                <td class="text-right text-no-wrap">{{ quantity(line.quantity) }} {{ line.unit }}</td>
                                <td class="text-right text-no-wrap">
                                    {{ formatMoney(line.unit_price) }}
                                    <div v-if="line.discount_percent" class="text-caption text-medium-emphasis">
                                        sconto {{ line.discount_percent }}%
                                    </div>
                                </td>
                                <td class="text-right text-no-wrap">{{ line.vat_nature ?? `${line.vat_rate}%` }}</td>
                                <td class="text-right text-no-wrap">{{ formatMoney(line.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right text-medium-emphasis">
                                    Canone {{ subscription.billing_period_label.toLowerCase() }}
                                </td>
                                <td class="text-right font-weight-bold text-no-wrap">{{ formatMoney(subscription.amount) }}</td>
                            </tr>
                        </tfoot>
                    </v-table>
                </v-card>

                <v-card class="mb-6" title="Prossimi canoni">
                    <v-table v-if="upcoming.length" density="compact">
                        <thead>
                            <tr>
                                <th>Periodo</th>
                                <th>Si fattura il</th>
                                <th class="text-right">Importo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="period in upcoming" :key="period.starts_on">
                                <td>{{ period.label }}</td>
                                <td class="text-no-wrap">
                                    {{ formatDate(period.due_on) }}
                                    <v-chip v-if="period.is_due" size="x-small" color="warning" variant="tonal" class="ml-1">da fatturare</v-chip>
                                </td>
                                <td class="text-right text-no-wrap">{{ formatMoney(subscription.amount) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">
                        {{
                            subscription.is_active
                                ? 'Nessun altro canone: il contratto è terminato.'
                                : 'Contratto sospeso: i canoni non vengono proposti.'
                        }}
                    </v-card-text>
                </v-card>

                <v-card title="Canoni in fattura">
                    <v-table v-if="subscription.periods.length" density="compact">
                        <thead>
                            <tr>
                                <th>Periodo</th>
                                <th>Fattura</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="period in subscription.periods"
                                :key="period.id"
                                class="cursor-pointer"
                                @click="router.visit(route('sales.invoices.show', period.invoice.id))"
                            >
                                <td>{{ period.label }}</td>
                                <td>{{ period.invoice.label }}</td>
                                <td>
                                    <v-chip size="small" :color="period.invoice.status_color" variant="tonal">
                                        {{ period.invoice.status_label }}
                                    </v-chip>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessun canone ancora in fattura.</v-card-text>
                    <v-card-text v-if="subscription.periods.length" class="text-caption text-medium-emphasis pt-0">
                        Eliminando una bozza (o una fattura mai inviata allo SDI) i suoi canoni tornano da fatturare.
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <v-card title="Dettagli">
                    <v-list density="compact">
                        <v-list-item
                            prepend-icon="mdi-domain"
                            title="Cliente"
                            :subtitle="subscription.organization?.name"
                            v-bind="link(route('registry.organizations.show', subscription.organization_id))"
                        />
                        <v-list-item
                            prepend-icon="mdi-autorenew"
                            title="Canone"
                            :subtitle="`${subscription.billing_period_label}, ${subscription.billing_mode_label.toLowerCase()}`"
                        />
                        <v-list-item
                            prepend-icon="mdi-calendar-range"
                            title="Durata"
                            :subtitle="`dal ${formatDate(subscription.starts_on)} ${subscription.ends_on ? `al ${formatDate(subscription.ends_on)}` : '· si rinnova da solo'}`"
                        />
                        <v-list-item
                            v-if="subscription.billed_until"
                            prepend-icon="mdi-history"
                            title="Già fatturato fuori da Prugna"
                            :subtitle="`fino al ${formatDate(subscription.billed_until)} · Prugna fattura dal ${formatDate(subscription.billing_start)}`"
                        />
                        <v-list-item prepend-icon="mdi-cash-clock" title="Pagamento" :subtitle="subscription.payment_term ?? 'Quello del cliente'" />
                        <v-list-item prepend-icon="mdi-account-tie-outline" title="Responsabile" :subtitle="subscription.owner ?? '—'" />
                    </v-list>
                    <v-card-text v-if="subscription.notes" class="text-body-2" style="white-space: pre-line">{{ subscription.notes }}</v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <SubscriptionInvoiceDialog v-model="generating" :subscription-id="subscription.id" />
    </div>
</template>
