<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { formatDate, formatMoney } from '@/utils/format';

const props = defineProps({
    accounts: { type: Array, required: true },
    selected: { type: Number, default: null },
    year: { type: Number, required: true },
    movements: { type: Array, required: true },
    receivables: { type: Array, required: true },
    open: { type: Object, required: true },
    dolibarr: { type: Object, required: true },
    accountTypes: { type: Array, required: true },
    categories: { type: Array, required: true },
    businessLines: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();

const account = computed(() => props.accounts.find((item) => item.id === props.selected) ?? null);
const activeAccounts = computed(() => props.accounts.filter((item) => item.is_active));
const accountItems = computed(() => activeAccounts.value.map((item) => ({ value: item.id, title: item.name })));
const total = computed(() => activeAccounts.value.reduce((sum, item) => sum + item.balance, 0));
const years = computed(() => {
    const current = new Date().getFullYear();

    return Array.from({ length: 6 }, (_, index) => current - index);
});
const yearIn = computed(() => props.movements.filter((item) => item.amount > 0).reduce((sum, item) => sum + item.amount, 0));
const yearOut = computed(() => props.movements.filter((item) => item.amount < 0).reduce((sum, item) => sum - item.amount, 0));

function visit(params) {
    router.get(
        route('accounting.cash.index'),
        { account: props.selected ?? undefined, year: props.year, ...params },
        { preserveScroll: true, preserveState: true },
    );
}

const today = () => new Date().toLocaleDateString('sv-SE');

// Accounts.
const accountDialog = ref(false);
const editingAccount = ref(null);
const accountForm = useForm({ name: '', type: 'bank', iban: '', opening_balance: 0, opening_date: null, is_active: true });

function openAccount(item = null) {
    editingAccount.value = item;
    accountForm.clearErrors();
    Object.assign(accountForm, {
        name: item?.name ?? '',
        type: item?.type ?? 'bank',
        iban: item?.iban ?? '',
        opening_balance: item?.opening_balance ?? 0,
        opening_date: item?.opening_date ?? today(),
        is_active: item?.is_active ?? true,
    });
    accountDialog.value = true;
}

function saveAccount() {
    const options = { preserveScroll: true, onSuccess: () => (accountDialog.value = false) };

    if (editingAccount.value) {
        accountForm.put(route('accounting.accounts.update', editingAccount.value.id), options);
    } else {
        accountForm.post(route('accounting.accounts.store'), options);
    }
}

async function destroyAccount(item) {
    if (await confirm({ title: `Eliminare il conto ${item.name}?`, confirmText: 'Elimina', color: 'error' })) {
        accountDialog.value = false;
        router.delete(route('accounting.accounts.destroy', item.id));
    }
}

// Movements without a document.
const movementDialog = ref(false);
const editingMovement = ref(null);
const movementForm = useForm({
    financial_account_id: null,
    date: null,
    direction: 'out',
    amount: null,
    description: '',
    category_id: null,
    business_line_id: null,
});
const categoryItems = computed(() =>
    props.categories.filter((item) => (movementForm.direction === 'in' ? item.kind === 'revenue' : item.kind !== 'revenue')),
);

function openMovement(item = null) {
    editingMovement.value = item;
    movementForm.clearErrors();
    Object.assign(movementForm, {
        financial_account_id: props.selected,
        date: item?.date ?? today(),
        direction: item && item.amount > 0 ? 'in' : 'out',
        amount: item ? Math.abs(item.amount) : null,
        description: item?.description ?? '',
        category_id: item?.category_id ?? null,
        business_line_id: item?.business_line_id ?? null,
    });
    movementDialog.value = true;
}

function saveMovement() {
    const options = { preserveScroll: true, onSuccess: () => (movementDialog.value = false) };

    if (editingMovement.value) {
        movementForm.put(route('accounting.movements.update', editingMovement.value.id), options);
    } else {
        movementForm.post(route('accounting.movements.store'), options);
    }
}

async function destroyMovement(item) {
    const message = item.invoice
        ? `La fattura ${item.invoice.label} tornerà da incassare per ${formatMoney(Math.abs(item.amount))}.`
        : item.transfer
          ? 'Vengono eliminati entrambi i lati del giroconto.'
          : `${formatMoney(item.amount)} del ${formatDate(item.date)}.`;

    if (await confirm({ title: 'Eliminare il movimento?', message, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('accounting.movements.destroy', item.id), { preserveScroll: true });
    }
}

// Transfers.
const transferDialog = ref(false);
const transferForm = useForm({ from_account_id: null, to_account_id: null, date: null, amount: null, description: '' });

function openTransfer() {
    transferForm.clearErrors();
    Object.assign(transferForm, { from_account_id: props.selected, to_account_id: null, date: today(), amount: null, description: '' });
    transferDialog.value = true;
}

function saveTransfer() {
    transferForm.post(route('accounting.transfers.store'), { preserveScroll: true, onSuccess: () => (transferDialog.value = false) });
}

// Collections written by the Dolibarr import in the invoice notes.
const dolibarrForm = useForm({ financial_account_id: null });

function importDolibarr() {
    dolibarrForm.post(route('accounting.dolibarr-collections.store'), { preserveScroll: true });
}
</script>

<template>
    <div>
        <PageHeader title="Cassa e banca" subtitle="Conti, incassi, pagamenti e movimenti senza documento">
            <template v-if="canManage && accounts.length" #actions>
                <v-btn variant="text" prepend-icon="mdi-plus" @click="openAccount()">Nuovo conto</v-btn>
                <v-btn variant="tonal" prepend-icon="mdi-swap-horizontal" :disabled="activeAccounts.length < 2" @click="openTransfer">
                    Giroconto
                </v-btn>
                <v-btn color="primary" prepend-icon="mdi-plus" :disabled="!account" @click="openMovement()">Movimento</v-btn>
            </template>
        </PageHeader>

        <v-row class="mb-2">
            <v-col cols="12" sm="4">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Saldo dei conti</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(total) }}</v-card-title>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card>
                    <v-card-item>
                        <v-card-subtitle>Da incassare ({{ open.receivable_count }} fatture)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(open.receivable) }}</v-card-title>
                        <div v-if="open.overdue" class="text-caption text-warning">di cui scaduti {{ formatMoney(open.overdue) }}</div>
                    </v-card-item>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card v-bind="link(route('purchases.expenses.index', { status: 'unpaid' }))">
                    <v-card-item>
                        <v-card-subtitle>Da pagare ({{ open.payable_count }} spese)</v-card-subtitle>
                        <v-card-title class="font-weight-bold">{{ formatMoney(open.payable) }}</v-card-title>
                        <div v-if="open.payable_overdue" class="text-caption text-warning">
                            di cui scaduti {{ formatMoney(open.payable_overdue) }}
                        </div>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

        <v-card v-if="!accounts.length" class="mb-6">
            <v-empty-state
                icon="mdi-bank-outline"
                title="Nessun conto"
                text="Crea il conto corrente (e se vuoi carta e contanti) con il saldo di oggi: da lì in poi incassi e pagamenti aggiornano il saldo."
                size="48"
            >
                <template v-if="canManage" #actions>
                    <v-btn color="primary" prepend-icon="mdi-plus" @click="openAccount()">Crea il primo conto</v-btn>
                </template>
            </v-empty-state>
        </v-card>

        <v-alert v-if="canManage && dolibarr.invoices && accounts.length" type="info" variant="tonal" class="mb-6" icon="mdi-database-import-outline">
            <div class="mb-2">
                {{ dolibarr.invoices }} fatture importate da Dolibarr hanno gli incassi nelle note interne: recuperali come incassi, così il conto
                economico per cassa ha anche lo storico. Non cambiano il saldo se sono precedenti alla data del saldo iniziale.
            </div>
            <div class="d-flex flex-wrap align-center ga-2">
                <v-select
                    v-model="dolibarrForm.financial_account_id"
                    :items="accountItems"
                    label="Sul conto"
                    density="compact"
                    hide-details
                    style="max-width: 260px"
                    :error-messages="dolibarrForm.errors.financial_account_id"
                />
                <v-btn
                    color="primary"
                    variant="flat"
                    :loading="dolibarrForm.processing"
                    :disabled="!dolibarrForm.financial_account_id"
                    @click="importDolibarr"
                >
                    Recupera gli incassi
                </v-btn>
            </div>
        </v-alert>

        <v-row v-if="accounts.length">
            <v-col cols="12" lg="8">
                <v-card class="mb-6">
                    <v-tabs :model-value="selected" color="primary" show-arrows @update:model-value="(id) => visit({ account: id })">
                        <v-tab
                            v-for="item in accounts"
                            :key="item.id"
                            :value="item.id"
                            :prepend-icon="item.icon"
                            :class="{ 'text-disabled': !item.is_active }"
                        >
                            {{ item.name }}
                            <span class="ml-2 text-medium-emphasis">{{ formatMoney(item.balance) }}</span>
                        </v-tab>
                    </v-tabs>
                    <v-divider />
                    <v-card-text v-if="account" class="d-flex flex-wrap align-center ga-3">
                        <div class="flex-grow-1 text-body-2">
                            <div>
                                <strong>{{ formatMoney(account.balance) }}</strong>
                                <span class="text-medium-emphasis">
                                    · saldo iniziale {{ formatMoney(account.opening_balance) }}
                                    <template v-if="account.opening_date">al {{ formatDate(account.opening_date) }}</template>
                                </span>
                            </div>
                            <div v-if="account.iban" class="text-caption text-medium-emphasis">{{ account.iban }}</div>
                        </div>
                        <v-select
                            :model-value="year"
                            :items="years"
                            label="Anno"
                            density="compact"
                            hide-details
                            style="max-width: 120px"
                            @update:model-value="(value) => visit({ year: value })"
                        />
                        <v-btn v-if="canManage" variant="text" size="small" prepend-icon="mdi-pencil-outline" @click="openAccount(account)">
                            Conto
                        </v-btn>
                    </v-card-text>
                    <v-divider />
                    <v-table v-if="movements.length" density="compact">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Movimento</th>
                                <th>Categoria</th>
                                <th class="text-right">Importo</th>
                                <th v-if="canManage" style="width: 72px" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in movements" :key="item.id">
                                <td class="text-no-wrap">{{ formatDate(item.date) }}</td>
                                <td class="py-1">
                                    <div class="text-body-2">{{ item.description || '—' }}</div>
                                    <div class="text-caption">
                                        <a v-if="item.invoice" v-bind="link(route('sales.invoices.show', item.invoice.id))">
                                            Fattura {{ item.invoice.label }}
                                        </a>
                                        <a v-else-if="item.expense" v-bind="link(route('purchases.expenses.show', item.expense.id))">
                                            Spesa {{ item.expense.label }}
                                        </a>
                                        <span v-else-if="item.transfer" class="text-medium-emphasis">Giroconto</span>
                                    </div>
                                </td>
                                <td class="text-caption">
                                    <template v-if="item.category">{{ item.category }}</template>
                                    <span v-else-if="item.manual" class="text-warning">da classificare</span>
                                    <div v-if="item.business_line" class="text-medium-emphasis">{{ item.business_line }}</div>
                                </td>
                                <td class="text-right text-no-wrap font-weight-medium" :class="item.amount < 0 ? 'text-error' : 'text-success'">
                                    {{ item.amount > 0 ? '+' : '−' }} {{ formatMoney(Math.abs(item.amount)) }}
                                </td>
                                <td v-if="canManage" class="text-no-wrap">
                                    <v-btn
                                        v-if="item.manual"
                                        icon="mdi-pencil-outline"
                                        size="x-small"
                                        variant="text"
                                        title="Modifica"
                                        @click="openMovement(item)"
                                    />
                                    <v-btn
                                        v-if="!item.expense"
                                        icon="mdi-delete-outline"
                                        size="x-small"
                                        variant="text"
                                        title="Elimina"
                                        @click="destroyMovement(item)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-right text-medium-emphasis">Entrate e uscite {{ year }}</td>
                                <td class="text-right text-no-wrap">
                                    <div class="text-success">+ {{ formatMoney(yearIn) }}</div>
                                    <div class="text-error">− {{ formatMoney(yearOut) }}</div>
                                </td>
                                <td v-if="canManage" />
                            </tr>
                        </tfoot>
                    </v-table>
                    <v-card-text v-else class="text-medium-emphasis">
                        Nessun movimento nel {{ year }}. Gli incassi si registrano dalla fattura, i pagamenti dalla spesa; qui aggiungi quelli senza
                        documento (commissioni, F24, contributi, prelievi).
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" lg="4">
                <v-card title="Fatture da incassare" :subtitle="`${open.receivable_count} · ${formatMoney(open.receivable)}`">
                    <v-list v-if="receivables.length" density="compact">
                        <v-list-item
                            v-for="item in receivables"
                            :key="item.id"
                            :title="item.customer"
                            :subtitle="`${item.label} · ${item.due_date ? `scade il ${formatDate(item.due_date)}` : formatDate(item.issue_date)}`"
                            v-bind="link(route('sales.invoices.show', item.id))"
                        >
                            <template #append>
                                <span class="text-no-wrap" :class="{ 'text-warning font-weight-medium': item.overdue }">
                                    {{ formatMoney(item.outstanding) }}
                                </span>
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-card-text v-else class="text-medium-emphasis">Tutto incassato.</v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="accountDialog" max-width="480">
            <v-card :title="editingAccount ? 'Modifica conto' : 'Nuovo conto'">
                <v-card-text>
                    <v-text-field
                        v-model="accountForm.name"
                        label="Nome"
                        placeholder="Es. Fineco"
                        :error-messages="accountForm.errors.name"
                        class="mb-2"
                    />
                    <v-select v-model="accountForm.type" :items="accountTypes" label="Tipo" class="mb-2" />
                    <v-text-field
                        v-if="accountForm.type === 'bank'"
                        v-model="accountForm.iban"
                        label="IBAN"
                        :error-messages="accountForm.errors.iban"
                        class="mb-2"
                    />
                    <v-row dense>
                        <v-col cols="6">
                            <v-text-field
                                v-model.number="accountForm.opening_balance"
                                type="number"
                                step="0.01"
                                prefix="€"
                                label="Saldo iniziale"
                                :error-messages="accountForm.errors.opening_balance"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="accountForm.opening_date"
                                type="date"
                                label="Al giorno"
                                :error-messages="accountForm.errors.opening_date"
                            />
                        </v-col>
                    </v-row>
                    <div class="text-caption text-medium-emphasis mb-2">
                        Il saldo conta i movimenti da questo giorno in poi: metti il saldo reale del conto.
                    </div>
                    <v-switch v-if="editingAccount" v-model="accountForm.is_active" label="Conto attivo" color="primary" hide-details />
                </v-card-text>
                <v-card-actions>
                    <v-btn
                        v-if="editingAccount && !editingAccount.movements_count"
                        variant="text"
                        color="error"
                        @click="destroyAccount(editingAccount)"
                    >
                        Elimina
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="accountDialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="accountForm.processing" @click="saveAccount">Salva</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="movementDialog" max-width="520">
            <v-card :title="editingMovement ? 'Modifica movimento' : 'Nuovo movimento'" subtitle="Entrate e uscite senza fattura o spesa">
                <v-card-text>
                    <v-btn-toggle v-model="movementForm.direction" mandatory density="compact" color="primary" variant="outlined" class="mb-4">
                        <v-btn value="out" prepend-icon="mdi-arrow-up">Uscita</v-btn>
                        <v-btn value="in" prepend-icon="mdi-arrow-down">Entrata</v-btn>
                    </v-btn-toggle>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="movementForm.financial_account_id"
                                :items="accountItems"
                                label="Conto"
                                :error-messages="movementForm.errors.financial_account_id"
                            />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-text-field v-model="movementForm.date" type="date" label="Data" :error-messages="movementForm.errors.date" />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-text-field
                                v-model.number="movementForm.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="€"
                                label="Importo"
                                :error-messages="movementForm.errors.amount"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="movementForm.description"
                                label="Descrizione"
                                placeholder="Es. Canone conto, F24 saldo imposta sostitutiva"
                                :error-messages="movementForm.errors.description"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-autocomplete
                                v-model="movementForm.category_id"
                                :items="categoryItems"
                                label="Categoria"
                                clearable
                                hint="Senza categoria (es. prelievi personali) resta fuori dal conto economico"
                                persistent-hint
                                :error-messages="movementForm.errors.category_id"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select v-model="movementForm.business_line_id" :items="businessLines" label="Linea di attività" clearable />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="movementDialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="movementForm.processing" @click="saveMovement">Salva</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="transferDialog" max-width="480">
            <v-card title="Giroconto" subtitle="Soldi spostati tra due conti: non è né un costo né un ricavo">
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="transferForm.from_account_id"
                                :items="accountItems"
                                label="Dal conto"
                                :error-messages="transferForm.errors.from_account_id"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="transferForm.to_account_id"
                                :items="accountItems"
                                label="Al conto"
                                :error-messages="transferForm.errors.to_account_id"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field v-model="transferForm.date" type="date" label="Data" :error-messages="transferForm.errors.date" />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model.number="transferForm.amount"
                                type="number"
                                step="0.01"
                                min="0"
                                prefix="€"
                                label="Importo"
                                :error-messages="transferForm.errors.amount"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field v-model="transferForm.description" label="Descrizione" placeholder="Giroconto" />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="transferDialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="transferForm.processing" @click="saveTransfer">Registra</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
