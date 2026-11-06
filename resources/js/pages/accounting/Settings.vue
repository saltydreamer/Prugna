<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    categories: { type: Array, required: true },
    lines: { type: Array, required: true },
    kinds: { type: Array, required: true },
    /** Parameters of the flat-rate tax estimate. */
    tax: { type: Object, required: true },
    inpsOptions: { type: Array, required: true },
    flatRate: { type: Boolean, default: false },
    regime: { type: String, default: null },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();

// Categories.
const categoryDialog = ref(false);
const editingCategory = ref(null);
const categoryForm = useForm({ name: '', kind: 'cost', business_share: 100, is_active: true });

function openCategory(item = null) {
    editingCategory.value = item;
    categoryForm.clearErrors();
    Object.assign(categoryForm, {
        name: item?.name ?? '',
        kind: item?.kind ?? 'cost',
        business_share: item?.business_share ?? 100,
        is_active: item?.is_active ?? true,
    });
    categoryDialog.value = true;
}

function saveCategory() {
    const options = { preserveScroll: true, onSuccess: () => (categoryDialog.value = false) };

    if (editingCategory.value) {
        categoryForm.put(route('accounting.settings.categories.update', editingCategory.value.id), options);
    } else {
        categoryForm.post(route('accounting.settings.categories.store'), options);
    }
}

async function destroyCategory(item) {
    if (await confirm({ title: `Eliminare la categoria ${item.name}?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('accounting.settings.categories.destroy', item.id), { preserveScroll: true });
    }
}

// Lines of business.
const lineDialog = ref(false);
const editingLine = ref(null);
const lineForm = useForm({ name: '', is_active: true });

function openLine(item = null) {
    editingLine.value = item;
    lineForm.clearErrors();
    Object.assign(lineForm, { name: item?.name ?? '', is_active: item?.is_active ?? true });
    lineDialog.value = true;
}

function saveLine() {
    const options = { preserveScroll: true, onSuccess: () => (lineDialog.value = false) };

    if (editingLine.value) {
        lineForm.put(route('accounting.settings.lines.update', editingLine.value.id), options);
    } else {
        lineForm.post(route('accounting.settings.lines.store'), options);
    }
}

async function destroyLine(item) {
    const used = item.products + item.expenses;
    const message = used ? `${item.products} prodotti e ${item.expenses} spese resteranno senza linea.` : undefined;

    if (await confirm({ title: `Eliminare la linea ${item.name}?`, message, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('accounting.settings.lines.destroy', item.id), { preserveScroll: true });
    }
}

// Flat-rate tax estimate.
const taxForm = useForm({ ...props.tax });

function saveTax() {
    taxForm.put(route('accounting.settings.tax.update'), { preserveScroll: true });
}
</script>

<template>
    <div>
        <PageHeader title="Categorie e attività" subtitle="Come imputi costi e ricavi, e i parametri per la stima delle tasse">
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('accounting.index'))">Conto economico</v-btn>
            </template>
        </PageHeader>

        <v-row>
            <v-col cols="12" lg="7">
                <v-card class="mb-6">
                    <v-card-item>
                        <v-card-title>Categorie</v-card-title>
                        <v-card-subtitle class="text-wrap">
                            Per spese e movimenti. La quota aziendale è quella proposta per i costi misti (es. auto 50%): la cambi su ogni spesa.
                        </v-card-subtitle>
                        <template #append>
                            <v-btn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openCategory()">Categoria</v-btn>
                        </template>
                    </v-card-item>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th class="text-end">Quota aziendale</th>
                                <th class="text-end">Usata</th>
                                <th style="width: 72px" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in categories" :key="item.id" :class="{ 'text-disabled': !item.is_active }">
                                <td>
                                    {{ item.name }}
                                    <v-chip v-if="!item.is_active" size="x-small" variant="tonal" class="ml-1">disattivata</v-chip>
                                </td>
                                <td class="text-caption">{{ item.kind_label }}</td>
                                <td class="text-end">{{ item.kind === 'revenue' ? '—' : `${item.business_share}%` }}</td>
                                <td class="text-end text-medium-emphasis">{{ item.used || '—' }}</td>
                                <td class="text-no-wrap">
                                    <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" title="Modifica" @click="openCategory(item)" />
                                    <v-btn
                                        v-if="!item.used"
                                        icon="mdi-delete-outline"
                                        size="x-small"
                                        variant="text"
                                        title="Elimina"
                                        @click="destroyCategory(item)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card>
            </v-col>

            <v-col cols="12" lg="5">
                <v-card class="mb-6">
                    <v-card-item>
                        <v-card-title>Linee di attività</v-card-title>
                        <v-card-subtitle class="text-wrap">
                            Es. Assistenza, Hosting e backup, Sviluppo: i ricavi arrivano dai prodotti del listino, i costi dalle spese.
                        </v-card-subtitle>
                        <template #append>
                            <v-btn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openLine()">Linea</v-btn>
                        </template>
                    </v-card-item>
                    <v-list v-if="lines.length" density="compact">
                        <v-list-item
                            v-for="item in lines"
                            :key="item.id"
                            :title="item.name"
                            :subtitle="`${item.products} prodotti · ${item.expenses} spese`"
                            :class="{ 'text-disabled': !item.is_active }"
                        >
                            <template #append>
                                <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" title="Modifica" @click="openLine(item)" />
                                <v-btn icon="mdi-delete-outline" size="x-small" variant="text" title="Elimina" @click="destroyLine(item)" />
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-card-text v-else class="text-medium-emphasis">
                        Nessuna linea: senza, il conto economico mostra tutto insieme. Dopo averle create, assegnale ai prodotti in
                        <a v-bind="link(route('catalog.products.index'))">Prodotti e servizi</a>
                        .
                    </v-card-text>
                </v-card>

                <v-card class="mb-6" title="Stima tasse (forfettario)">
                    <v-card-text>
                        <v-alert v-if="!flatRate" type="info" variant="tonal" density="compact" class="mb-3">
                            La stima compare nel conto economico solo con il regime forfettario (RF19); il regime attuale è {{ regime }}.
                        </v-alert>
                        <v-row dense>
                            <v-col cols="6">
                                <v-text-field
                                    v-model.number="taxForm.coefficient"
                                    type="number"
                                    suffix="%"
                                    label="Coefficiente di redditività"
                                    hint="Dal codice ATECO: 67% per i servizi informatici"
                                    persistent-hint
                                    :error-messages="taxForm.errors.coefficient"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field
                                    v-model.number="taxForm.tax_rate"
                                    type="number"
                                    suffix="%"
                                    label="Imposta sostitutiva"
                                    hint="15%, o 5% nei primi 5 anni di attività"
                                    persistent-hint
                                    :error-messages="taxForm.errors.tax_rate"
                                />
                            </v-col>
                            <v-col cols="12">
                                <v-select v-model="taxForm.inps_regime" :items="inpsOptions" label="Contributi INPS" class="mt-2" />
                            </v-col>
                            <v-col v-if="taxForm.inps_regime !== 'none'" cols="6">
                                <v-text-field
                                    v-model.number="taxForm.inps_rate"
                                    type="number"
                                    step="0.01"
                                    suffix="%"
                                    :label="taxForm.inps_regime === 'separata' ? 'Aliquota' : 'Aliquota oltre il minimale'"
                                    :hint="taxForm.inps_regime === 'separata' ? '26,07% nel 2025' : '24% artigiani, 24,48% commercianti nel 2025'"
                                    persistent-hint
                                    :error-messages="taxForm.errors.inps_rate"
                                />
                            </v-col>
                            <template v-if="taxForm.inps_regime === 'artigiani_commercianti'">
                                <v-col cols="6">
                                    <v-text-field
                                        v-model.number="taxForm.inps_fixed"
                                        type="number"
                                        step="0.01"
                                        prefix="€"
                                        label="Contributi fissi annui"
                                        :error-messages="taxForm.errors.inps_fixed"
                                    />
                                </v-col>
                                <v-col cols="6">
                                    <v-text-field
                                        v-model.number="taxForm.inps_minimum_income"
                                        type="number"
                                        prefix="€"
                                        label="Reddito minimale"
                                        :error-messages="taxForm.errors.inps_minimum_income"
                                    />
                                </v-col>
                                <v-col cols="6">
                                    <v-switch v-model="taxForm.inps_reduction" label="Riduzione 35%" color="primary" hide-details />
                                </v-col>
                            </template>
                        </v-row>
                        <div class="text-caption text-medium-emphasis mt-3">
                            I valori INPS cambiano ogni anno: aggiornali con quelli della circolare dell'anno (o chiedi al commercialista).
                        </div>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn color="primary" variant="flat" :loading="taxForm.processing" @click="saveTax">Salva parametri</v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="categoryDialog" max-width="440">
            <v-card :title="editingCategory ? 'Modifica categoria' : 'Nuova categoria'">
                <v-card-text>
                    <v-text-field v-model="categoryForm.name" label="Nome" :error-messages="categoryForm.errors.name" class="mb-2" />
                    <v-select
                        v-model="categoryForm.kind"
                        :items="kinds"
                        label="Tipo"
                        hint="Imposte e contributi stanno sotto il margine operativo; i contributi versati riducono il reddito del forfettario"
                        persistent-hint
                        class="mb-4"
                    />
                    <v-text-field
                        v-if="categoryForm.kind !== 'revenue'"
                        v-model.number="categoryForm.business_share"
                        type="number"
                        min="0"
                        max="100"
                        suffix="%"
                        label="Quota aziendale proposta"
                        :error-messages="categoryForm.errors.business_share"
                        class="mb-2"
                    />
                    <v-switch v-if="editingCategory" v-model="categoryForm.is_active" label="Attiva" color="primary" hide-details />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="categoryDialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="categoryForm.processing" @click="saveCategory">Salva</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="lineDialog" max-width="400">
            <v-card :title="editingLine ? 'Modifica linea' : 'Nuova linea di attività'">
                <v-card-text>
                    <v-text-field v-model="lineForm.name" label="Nome" placeholder="Es. Hosting e backup" :error-messages="lineForm.errors.name" />
                    <v-switch v-if="editingLine" v-model="lineForm.is_active" label="Attiva" color="primary" hide-details />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="lineDialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="lineForm.processing" @click="saveLine">Salva</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
