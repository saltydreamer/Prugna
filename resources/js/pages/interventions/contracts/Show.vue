<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney } from '@/utils/format';
import { formatHours } from '@/utils/duration';

const props = defineProps({
    contract: { type: Object, required: true },
    categories: { type: Array, required: true },
    interventions: { type: Array, required: true },
});

const { link } = useInertiaLink();
const { can } = usePermissions();
const { confirm } = useConfirm();

const percent = computed(() =>
    props.contract.total_minutes ? Math.min(100, Math.round((props.contract.used_minutes / props.contract.total_minutes) * 100)) : 0,
);

async function invoice() {
    const ok = await confirm({
        title: 'Fatturare il pacchetto?',
        message: `Viene preparata una bozza di fattura di ${formatMoney(props.contract.price)} + IVA per ${props.contract.organization?.name}.`,
        confirmText: 'Prepara bozza',
    });

    if (ok) {
        router.post(route('interventions.contracts.invoice', props.contract.id));
    }
}

async function destroy() {
    const ok = await confirm({ title: `Eliminare il contratto ${props.contract.number}?`, confirmText: 'Elimina', color: 'error' });

    if (ok) {
        router.delete(route('interventions.contracts.destroy', props.contract.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="contract.title" :subtitle="`${contract.number} · ${contract.organization?.name}`">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('interventions.contracts.index'))">Contratti</v-btn>
                <v-btn
                    v-if="!contract.invoice && contract.price > 0 && contract.can.update && can('invoices.manage')"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-receipt-text-plus-outline"
                    @click="invoice"
                >
                    Fattura il pacchetto
                </v-btn>
                <v-btn
                    v-if="contract.can.update"
                    variant="tonal"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('interventions.contracts.edit', contract.id))"
                >
                    Modifica
                </v-btn>
                <v-btn v-if="contract.can.delete" variant="text" color="error" icon="mdi-delete-outline" title="Elimina" @click="destroy" />
            </template>
        </PageHeader>

        <v-row>
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-card-item>
                        <v-card-title class="d-flex align-center ga-2">
                            Ore
                            <v-chip size="small" :color="contract.status_color" variant="tonal">{{ contract.status_label }}</v-chip>
                        </v-card-title>
                    </v-card-item>
                    <v-card-text>
                        <div class="d-flex justify-space-between text-body-2 mb-1">
                            <span>Usate {{ formatHours(contract.used_minutes) }} di {{ formatHours(contract.total_minutes) }}</span>
                            <strong>Residuo {{ formatHours(contract.remaining_minutes) }}</strong>
                        </div>
                        <v-progress-linear
                            :model-value="percent"
                            :color="percent >= 100 ? 'error' : percent >= 80 ? 'warning' : 'primary'"
                            rounded
                            height="10"
                        />
                    </v-card-text>
                </v-card>

                <v-card title="Interventi che hanno usato le ore">
                    <v-table v-if="interventions.length" density="compact">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Intervento</th>
                                <th class="text-right">Scalate</th>
                                <th class="text-right">In fattura</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in interventions"
                                :key="item.id"
                                class="cursor-pointer"
                                @click="router.visit(route('interventions.show', item.id))"
                            >
                                <td class="text-no-wrap">{{ formatDate(item.started_at) }}</td>
                                <td class="py-2">
                                    <div class="text-body-2">
                                        {{ item.category }} · {{ item.mode_label.toLowerCase() }}
                                        <span class="text-medium-emphasis">· {{ item.number }}</span>
                                    </div>
                                    <div v-if="item.description" class="text-caption text-medium-emphasis text-truncate" style="max-width: 420px">
                                        {{ item.description }}
                                    </div>
                                </td>
                                <td class="text-right text-no-wrap">{{ formatHours(item.contract_minutes) }}</td>
                                <td class="text-right text-no-wrap">{{ item.invoiceable_minutes ? formatHours(item.invoiceable_minutes) : '—' }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">Nessun intervento ha ancora usato le ore di questo contratto.</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <v-card title="Dettagli">
                    <v-list density="compact">
                        <v-list-item
                            prepend-icon="mdi-domain"
                            title="Cliente"
                            :subtitle="contract.organization?.name"
                            v-bind="link(route('registry.organizations.show', contract.organization_id))"
                        />
                        <v-list-item
                            prepend-icon="mdi-calendar-range"
                            title="Validità"
                            :subtitle="`${formatDate(contract.starts_on)} → ${contract.ends_on ? formatDate(contract.ends_on) : 'senza scadenza'}`"
                        />
                        <v-list-item prepend-icon="mdi-shape-outline" title="Copre" :subtitle="`${contract.mode_label} · ${categories.join(', ')}`" />
                        <v-list-item prepend-icon="mdi-cash" title="Prezzo" :subtitle="`${formatMoney(contract.price)} + IVA`" />
                        <v-list-item
                            v-if="contract.invoice"
                            prepend-icon="mdi-receipt-text-check-outline"
                            title="Fattura"
                            :subtitle="contract.invoice.label"
                            v-bind="link(route('sales.invoices.show', contract.invoice.id))"
                        />
                        <v-list-item prepend-icon="mdi-account-tie-outline" title="Responsabile" :subtitle="contract.manager ?? '—'" />
                    </v-list>
                    <v-card-text v-if="contract.notes" class="text-body-2" style="white-space: pre-line">{{ contract.notes }}</v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>
