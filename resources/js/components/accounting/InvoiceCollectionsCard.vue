<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { useConfirm } from '@/composables/useConfirm';
import { formatDate, formatMoney } from '@/utils/format';

/**
 * Collections of an issued invoice on the accounts (refunds for a credit note).
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    /** { movements, paid_amount, paid_at, credited, outstanding, is_paid, can_record, accounts } */
    collections: { type: Object, required: true },
});

const { confirm } = useConfirm();
const dialog = ref(false);
const isCreditNote = computed(() => props.invoice.type === 'credit_note');

const form = useForm({ financial_account_id: null, date: null, amount: null });

const status = computed(() => {
    if (isCreditNote.value) {
        return props.collections.paid_amount > 0 ? { label: 'Rimborsata', color: 'success' } : null;
    }

    if (props.collections.is_paid) {
        return { label: 'Incassata', color: 'success' };
    }

    if (props.collections.credited > 0 && props.collections.outstanding <= 0) {
        return { label: 'Stornata', color: 'grey' };
    }

    return props.collections.paid_amount > 0 ? { label: 'Incassata in parte', color: 'warning' } : { label: 'Da incassare', color: 'warning' };
});

function open() {
    form.clearErrors();
    form.financial_account_id = props.collections.accounts.length === 1 ? props.collections.accounts[0].value : form.financial_account_id;
    form.date = new Date().toLocaleDateString('sv-SE');
    form.amount = isCreditNote.value ? props.invoice.amount_due : props.collections.outstanding || props.invoice.amount_due;
    dialog.value = true;
}

function save() {
    form.post(route('accounting.collections.store', props.invoice.id), { preserveScroll: true, onSuccess: () => (dialog.value = false) });
}

async function remove(movement) {
    if (
        await confirm({
            title: "Eliminare l'incasso?",
            message: `${formatMoney(Math.abs(movement.amount))} del ${formatDate(movement.date)}.`,
            confirmText: 'Elimina',
            color: 'error',
        })
    ) {
        router.delete(route('accounting.movements.destroy', movement.id), { preserveScroll: true });
    }
}
</script>

<template>
    <v-card :title="isCreditNote ? 'Rimborso' : 'Incassi'" prepend-icon="mdi-cash-check">
        <template v-if="status" #append>
            <v-chip :color="status.color" variant="tonal" size="small">{{ status.label }}</v-chip>
        </template>
        <v-card-text>
            <div v-if="!isCreditNote" class="text-body-2">
                <div class="d-flex justify-space-between">
                    <span class="text-medium-emphasis">Da incassare</span>
                    <span>{{ formatMoney(invoice.amount_due) }}</span>
                </div>
                <div v-if="collections.credited" class="d-flex justify-space-between">
                    <span class="text-medium-emphasis">Stornato con note di credito</span>
                    <span>− {{ formatMoney(collections.credited) }}</span>
                </div>
                <div class="d-flex justify-space-between">
                    <span class="text-medium-emphasis">Incassato</span>
                    <span>{{ formatMoney(collections.paid_amount) }}</span>
                </div>
                <div class="d-flex justify-space-between font-weight-medium">
                    <span>Residuo</span>
                    <span>{{ formatMoney(collections.outstanding) }}</span>
                </div>
            </div>
            <div v-else class="text-body-2 text-medium-emphasis">
                Se hai restituito dei soldi al cliente, registra qui il rimborso: esce dal conto e riduce i ricavi.
            </div>

            <v-table v-if="collections.movements.length" density="compact" class="mt-3">
                <tbody>
                    <tr v-for="movement in collections.movements" :key="movement.id">
                        <td class="text-no-wrap">{{ formatDate(movement.date) }}</td>
                        <td class="text-medium-emphasis">{{ movement.account }}</td>
                        <td class="text-right text-no-wrap">{{ formatMoney(Math.abs(movement.amount)) }}</td>
                        <td v-if="collections.can_record" style="width: 32px">
                            <v-btn icon="mdi-close" size="x-small" variant="text" title="Elimina" @click="remove(movement)" />
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card-text>
        <v-card-actions v-if="collections.can_record && (isCreditNote || collections.outstanding > 0)">
            <v-btn v-if="collections.accounts.length" color="primary" variant="tonal" prepend-icon="mdi-cash-plus" @click="open">
                {{ isCreditNote ? 'Registra rimborso' : 'Registra incasso' }}
            </v-btn>
            <span v-else class="text-caption text-medium-emphasis px-2">
                Crea un conto in Contabilità → Cassa e banca per registrare gli incassi.
            </span>
        </v-card-actions>

        <v-dialog v-model="dialog" max-width="420">
            <v-card :title="isCreditNote ? 'Registra rimborso' : 'Registra incasso'" :subtitle="invoice.number">
                <v-card-text>
                    <v-select
                        v-model="form.financial_account_id"
                        :items="collections.accounts"
                        :label="isCreditNote ? 'Dal conto' : 'Sul conto'"
                        :error-messages="form.errors.financial_account_id"
                        class="mb-2"
                    />
                    <v-text-field v-model="form.date" type="date" label="Data" :error-messages="form.errors.date" class="mb-2" />
                    <v-text-field
                        v-model.number="form.amount"
                        type="number"
                        step="0.01"
                        min="0"
                        prefix="€"
                        label="Importo"
                        :error-messages="form.errors.amount"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="form.processing" @click="save">Registra</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>
