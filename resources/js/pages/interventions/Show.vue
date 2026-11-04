<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import InterventionInvoiceDialog from '@/components/interventions/InterventionInvoiceDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/utils/format';
import { formatElapsed, formatHours, formatMinutes, ROUNDING_LABELS } from '@/utils/duration';

const props = defineProps({
    intervention: { type: Object, required: true },
    rounding: { type: Number, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const { confirm } = useConfirm();

const item = computed(() => props.intervention);
const invoiceDialog = ref(false);

const now = ref(Date.now());
let ticker;
onMounted(() => (ticker = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(ticker));

const dateTime = (iso) =>
    new Date(iso).toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const time = (iso) => new Date(iso).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
const materialsTotal = computed(() => (item.value.materials ?? []).reduce((sum, material) => sum + material.total, 0));

function stop() {
    router.post(route('interventions.stop', item.value.id));
}

async function destroy() {
    const ok = await confirm({
        title: `Eliminare l'intervento ${item.value.number}?`,
        message: 'Il numero non verrà riutilizzato.',
        confirmText: 'Elimina',
        color: 'error',
    });

    if (ok) {
        router.delete(route('interventions.destroy', item.value.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="`Intervento ${item.number}`" :subtitle="item.organization?.name">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('interventions.index'))">Interventi</v-btn>
                <v-btn v-if="item.running && item.can.update" color="error" variant="flat" prepend-icon="mdi-stop-circle-outline" @click="stop">
                    Termina
                </v-btn>
                <v-btn
                    v-if="item.status === 'to_invoice' && can('invoices.manage')"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-receipt-text-plus-outline"
                    @click="invoiceDialog = true"
                >
                    Fattura
                </v-btn>
                <v-btn v-if="item.can.update" variant="tonal" prepend-icon="mdi-pencil-outline" v-bind="link(route('interventions.edit', item.id))">
                    Modifica
                </v-btn>
                <v-btn v-if="item.can.delete" variant="text" color="error" icon="mdi-delete-outline" title="Elimina" @click="destroy" />
            </template>
        </PageHeader>

        <v-alert v-if="item.running" type="info" variant="tonal" class="mb-6" icon="mdi-timer-outline">
            <div class="d-flex align-center flex-wrap ga-2">
                <span>In corso dalle {{ time(item.started_at) }}:</span>
                <strong class="text-h6" style="font-variant-numeric: tabular-nums">{{ formatElapsed(item.started_at, now) }}</strong>
            </div>
        </v-alert>
        <v-alert v-else-if="item.status === 'invoiced'" type="success" variant="tonal" class="mb-6" icon="mdi-receipt-text-check-outline">
            Fatturato in
            <template v-for="(invoice, index) in item.invoices" :key="invoice.id">
                <span v-if="index">,</span>
                <a v-bind="link(route('sales.invoices.show', invoice.id))" class="font-weight-medium">{{ invoice.label }}</a>
            </template>
            : non si può più modificare.
        </v-alert>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-item>
                        <v-card-title class="d-flex align-center flex-wrap ga-2">
                            {{ item.category }}
                            <v-chip size="small" variant="tonal" :prepend-icon="item.mode_icon">{{ item.mode_label }}</v-chip>
                            <v-chip size="small" :color="item.status_color" variant="tonal">{{ item.status_label }}</v-chip>
                        </v-card-title>
                        <v-card-subtitle class="text-wrap">
                            {{ dateTime(item.started_at) }}
                            <template v-if="item.ends_at">– {{ time(item.ends_at) }}</template>
                        </v-card-subtitle>
                    </v-card-item>
                    <v-card-text>
                        <div v-if="item.description" class="text-body-1" style="white-space: pre-line">{{ item.description }}</div>
                        <div v-else class="text-medium-emphasis">Nessuna descrizione del lavoro svolto.</div>

                        <template v-if="item.internal_notes">
                            <div class="text-overline mt-4">Note interne</div>
                            <div class="text-body-2" style="white-space: pre-line">{{ item.internal_notes }}</div>
                        </template>
                    </v-card-text>
                </v-card>

                <v-card v-if="item.mode === 'onsite'" class="mb-6" title="Uscita, viaggio e materiali">
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-car-outline" title="Diritto di uscita" :subtitle="item.callout ? 'Sì' : 'No'" />
                        <v-list-item
                            prepend-icon="mdi-map-marker-distance"
                            title="Km percorsi"
                            :subtitle="item.km ? `${item.km.toLocaleString('it-IT')} km` : '—'"
                        />
                        <v-list-item
                            prepend-icon="mdi-timer-sand"
                            title="Tempo di viaggio"
                            :subtitle="
                                item.travel_minutes
                                    ? `${formatMinutes(item.travel_minutes)} (fatturato ${formatHours(item.billable_travel_minutes)})`
                                    : '—'
                            "
                        />
                    </v-list>
                    <template v-if="item.materials?.length">
                        <v-divider />
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>Materiale</th>
                                    <th class="text-right">Q.tà</th>
                                    <th class="text-right">Prezzo</th>
                                    <th class="text-right">Totale</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="material in item.materials" :key="material.id">
                                    <td>{{ material.description }}</td>
                                    <td class="text-right text-no-wrap">{{ material.quantity.toLocaleString('it-IT') }} {{ material.unit }}</td>
                                    <td class="text-right text-no-wrap">{{ formatMoney(material.unit_price) }}</td>
                                    <td class="text-right text-no-wrap">{{ formatMoney(material.total) }}</td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="text-right font-weight-medium">Totale materiali</td>
                                    <td class="text-right font-weight-medium text-no-wrap">{{ formatMoney(materialsTotal) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </template>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <v-card class="mb-6">
                    <v-card-text>
                        <div class="d-flex justify-space-between text-body-2 mb-1">
                            <span>Durata</span>
                            <span>{{ item.running ? 'in corso' : formatMinutes(item.duration_minutes) }}</span>
                        </div>
                        <div v-if="!item.running" class="d-flex justify-space-between text-body-2">
                            <span>Ore fatturate</span>
                            <strong>{{ item.billable ? formatHours(item.billable_minutes) : 'non fatturabile' }}</strong>
                        </div>
                        <div v-if="!item.running && item.billable" class="text-caption text-medium-emphasis mt-1">
                            Arrotondamento {{ ROUNDING_LABELS[rounding] ?? `a ${rounding} min` }}
                        </div>
                    </v-card-text>
                </v-card>

                <v-card title="Dettagli">
                    <v-list density="compact">
                        <v-list-item
                            prepend-icon="mdi-domain"
                            title="Cliente"
                            :subtitle="item.organization?.name"
                            v-bind="link(route('registry.organizations.show', item.organization_id))"
                        />
                        <v-list-item
                            v-if="item.location"
                            prepend-icon="mdi-map-marker-outline"
                            title="Sede"
                            :subtitle="item.location.address || item.location.name"
                        />
                        <v-list-item
                            v-if="item.contact"
                            prepend-icon="mdi-account-outline"
                            title="Referente"
                            :subtitle="[item.contact.name, item.contact.phone, item.contact.email].filter(Boolean).join(' · ')"
                        />
                        <v-list-item
                            v-if="item.order"
                            prepend-icon="mdi-clipboard-text-outline"
                            title="Ordine"
                            :subtitle="[item.order.number, item.order.title].filter(Boolean).join(' · ')"
                            v-bind="can('orders.view') ? link(route('sales.orders.show', item.order.id)) : {}"
                        />
                        <v-list-item prepend-icon="mdi-account-hard-hat-outline" title="Tecnico" :subtitle="item.technician ?? '—'" />
                    </v-list>
                </v-card>
            </v-col>
        </v-row>

        <InterventionInvoiceDialog v-model="invoiceDialog" :organization-id="item.organization_id" :preselect="[item.id]" />
    </div>
</template>
