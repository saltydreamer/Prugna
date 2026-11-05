<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatDate, formatMoney } from '@/utils/format';
import { http } from '@/utils/http';

/**
 * «Genera fatture da servizi»: the fees of the service contracts due by a date, grouped by customer.
 * The chosen ones become draft invoices (one per customer) to check and issue.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    /** Only the fees of this contract. */
    subscriptionId: { type: Number, default: null },
});

const today = () => new Date().toLocaleDateString('sv-SE');

const date = ref(today());
const groups = ref([]);
const selected = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');

const fees = computed(() => groups.value.flatMap((group) => group.fees));
const chosen = computed(() => fees.value.filter((fee) => selected.value.includes(fee.key)));
const total = computed(() => chosen.value.reduce((sum, fee) => sum + fee.amount, 0));
const customers = computed(() => groups.value.filter((group) => group.fees.some((fee) => selected.value.includes(fee.key))).length);
const allSelected = computed({
    get: () => fees.value.length > 0 && selected.value.length === fees.value.length,
    set: (value) => (selected.value = value ? fees.value.map((fee) => fee.key) : []),
});

async function load() {
    loading.value = true;
    error.value = '';

    try {
        const query = new URLSearchParams({ date: date.value || today() });

        if (props.subscriptionId) {
            query.set('subscription', props.subscriptionId);
        }

        const { data } = await http.get(`${route('sales.subscriptions.due')}?${query}`);
        groups.value = data;
        selected.value = data.flatMap((group) => group.fees.map((fee) => fee.key));
    } catch (exception) {
        error.value = exception.message;
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => {
    if (value) {
        date.value = today();
        load();
    }
});

watch(date, () => open.value && date.value && load());

function toggleGroup(group, value) {
    const keys = group.fees.map((fee) => fee.key);
    selected.value = value ? [...new Set([...selected.value, ...keys])] : selected.value.filter((key) => !keys.includes(key));
}

const groupState = (group) => {
    const count = group.fees.filter((fee) => selected.value.includes(fee.key)).length;

    return { all: count === group.fees.length, some: count > 0 && count < group.fees.length };
};

function generate() {
    saving.value = true;
    error.value = '';

    router.post(
        route('sales.subscriptions.generate'),
        { date: date.value, fees: selected.value, subscription: props.subscriptionId ?? undefined },
        {
            onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Operazione non riuscita.'),
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <v-dialog v-model="open" max-width="860" scrollable>
        <v-card title="Genera fatture da servizi" subtitle="Canoni dei contratti non ancora in fattura" :loading="loading">
            <v-card-text>
                <v-row dense align="center" class="mb-2">
                    <v-col cols="12" sm="5">
                        <v-text-field
                            v-model="date"
                            type="date"
                            label="Canoni dovuti fino al"
                            hint="Sposta la data avanti per fatturare in anticipo"
                            persistent-hint
                        />
                    </v-col>
                    <v-col cols="12" sm="7" class="text-body-2 text-medium-emphasis">
                        Si crea una bozza per cliente, con una riga per servizio e periodo. Le bozze non partono da sole: le controlli e le emetti tu.
                    </v-col>
                </v-row>

                <v-empty-state
                    v-if="!loading && !fees.length"
                    icon="mdi-calendar-check-outline"
                    title="Nessun canone da fatturare"
                    text="Tutti i canoni dovuti fino a questa data sono già in fattura."
                    size="48"
                />

                <v-table v-else density="compact">
                    <thead>
                        <tr>
                            <th style="width: 40px"><v-checkbox-btn v-model="allSelected" density="compact" /></th>
                            <th>Contratto e periodo</th>
                            <th>Da fatturare il</th>
                            <th class="text-right">Importo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in groups" :key="group.organization.id">
                            <tr class="subscription-dialog__customer">
                                <td>
                                    <v-checkbox-btn
                                        :model-value="groupState(group).all"
                                        :indeterminate="groupState(group).some"
                                        density="compact"
                                        @update:model-value="(value) => toggleGroup(group, value)"
                                    />
                                </td>
                                <td colspan="2" class="font-weight-medium">{{ group.organization.name }}</td>
                                <td class="text-right font-weight-medium text-no-wrap">{{ formatMoney(group.total) }}</td>
                            </tr>
                            <tr v-for="fee in group.fees" :key="fee.key">
                                <td><v-checkbox-btn v-model="selected" :value="fee.key" density="compact" /></td>
                                <td class="py-1">
                                    <div class="text-body-2">{{ fee.title }} · {{ fee.period }}</div>
                                    <div class="text-caption text-medium-emphasis">{{ fee.number }}</div>
                                </td>
                                <td class="text-no-wrap">{{ formatDate(fee.due_on) }}</td>
                                <td class="text-right text-no-wrap">{{ formatMoney(fee.amount) }}</td>
                            </tr>
                        </template>
                    </tbody>
                </v-table>

                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-4">{{ error }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <span v-if="selected.length" class="text-body-2 ml-2">
                    {{ selected.length }} {{ selected.length === 1 ? 'canone' : 'canoni' }} · {{ customers }}
                    {{ customers === 1 ? 'cliente' : 'clienti' }} ·
                    <strong>{{ formatMoney(total) }}</strong>
                    + IVA
                </span>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-receipt-text-plus-outline"
                    :disabled="!selected.length || loading"
                    :loading="saving"
                    @click="generate"
                >
                    Crea le bozze
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.subscription-dialog__customer td {
    background: rgba(var(--v-theme-on-surface), 0.04);
}
</style>
