<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';

const props = defineProps({
    categories: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    natures: { type: Array, required: true },
    colors: { type: Array, required: true },
});

const { confirm } = useConfirm();
const tab = ref('categories');

// --- Categories ---------------------------------------------------------------
const categoryDialog = ref(false);
const editingCategory = ref(null);
const categoryForm = useForm({ name: '', color: 'primary', description: '' });

function openCategory(category = null) {
    editingCategory.value = category;
    categoryForm.clearErrors();
    categoryForm.defaults({ name: category?.name ?? '', color: category?.color ?? 'primary', description: category?.description ?? '' });
    categoryForm.reset();
    categoryDialog.value = true;
}

function saveCategory() {
    const options = { preserveScroll: true, onSuccess: () => (categoryDialog.value = false) };

    if (editingCategory.value) {
        categoryForm.put(route('catalog.settings.categories.update', editingCategory.value.id), options);
    } else {
        categoryForm.post(route('catalog.settings.categories.store'), options);
    }
}

async function deleteCategory(category) {
    const confirmed = await confirm({
        title: `Eliminare la categoria "${category.name}"?`,
        message: category.products_count ? `${category.products_count} articoli resteranno senza categoria.` : '',
        confirmText: 'Elimina',
        color: 'error',
    });

    if (confirmed) {
        router.delete(route('catalog.settings.categories.destroy', category.id), { preserveScroll: true });
    }
}

// --- VAT rates -----------------------------------------------------------------
const vatDialog = ref(false);
const editingVat = ref(null);
const vatForm = useForm({ name: '', rate: 22, nature: null, legal_reference: '', is_default: false, is_active: true, sort: 0 });
const exempt = computed(() => Number(vatForm.rate) === 0);

watch(exempt, (value) => {
    if (!value) {
        vatForm.nature = null;
    }
});

function openVat(rate = null) {
    editingVat.value = rate;
    vatForm.clearErrors();
    vatForm.defaults({
        name: rate?.name ?? '',
        rate: rate?.rate ?? 22,
        nature: rate?.nature ?? null,
        legal_reference: rate?.legal_reference ?? '',
        is_default: rate?.is_default ?? false,
        is_active: rate?.is_active ?? true,
        sort: rate?.sort ?? (props.vatRates.at(-1)?.sort ?? 0) + 10,
    });
    vatForm.reset();
    vatDialog.value = true;
}

function saveVat() {
    const options = { preserveScroll: true, onSuccess: () => (vatDialog.value = false) };
    const transform = (data) => ({ ...data, nature: exempt.value ? data.nature : null });

    if (editingVat.value) {
        vatForm.transform(transform).put(route('catalog.settings.vat-rates.update', editingVat.value.id), options);
    } else {
        vatForm.transform(transform).post(route('catalog.settings.vat-rates.store'), options);
    }
}

async function deleteVat(rate) {
    if (await confirm({ title: `Eliminare "${rate.name}"?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('catalog.settings.vat-rates.destroy', rate.id), { preserveScroll: true });
    }
}

const vatHeaders = [
    { title: 'Nome', key: 'name' },
    { title: 'Aliquota', key: 'rate', align: 'end' },
    { title: 'Natura', key: 'nature' },
    { title: 'Articoli', key: 'products_count', align: 'center' },
    { title: 'Stato', key: 'is_active' },
    { title: '', key: 'actions', align: 'end', sortable: false },
];
</script>

<template>
    <div>
        <PageHeader title="Categorie e IVA" subtitle="Impostazioni del catalogo prodotti e servizi." />

        <v-card class="mb-6">
            <v-tabs v-model="tab" color="primary">
                <v-tab value="categories" prepend-icon="mdi-shape-outline">Categorie</v-tab>
                <v-tab value="vat" prepend-icon="mdi-percent-outline">Aliquote IVA</v-tab>
            </v-tabs>
        </v-card>

        <v-tabs-window v-model="tab">
            <v-tabs-window-item value="categories">
                <v-card>
                    <v-card-item>
                        <v-card-title>Categorie</v-card-title>
                        <v-card-subtitle>Per organizzare e filtrare il listino.</v-card-subtitle>
                        <template #append>
                            <v-btn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openCategory()">Nuova categoria</v-btn>
                        </template>
                    </v-card-item>
                    <v-divider />
                    <v-list v-if="categories.length" class="py-0">
                        <template v-for="(category, index) in categories" :key="category.id">
                            <v-divider v-if="index > 0" />
                            <v-list-item :subtitle="category.description || `${category.products_count} articoli`">
                                <template #prepend>
                                    <v-avatar :color="category.color" size="12" class="mr-4" />
                                </template>
                                <v-list-item-title class="font-weight-medium">{{ category.name }}</v-list-item-title>
                                <template #append>
                                    <v-chip variant="tonal" class="mr-2">{{ category.products_count }}</v-chip>
                                    <v-btn icon="mdi-pencil-outline" variant="text" size="small" title="Modifica" @click="openCategory(category)" />
                                    <v-btn
                                        icon="mdi-delete-outline"
                                        variant="text"
                                        size="small"
                                        color="error"
                                        title="Elimina"
                                        @click="deleteCategory(category)"
                                    />
                                </template>
                            </v-list-item>
                        </template>
                    </v-list>
                    <v-empty-state v-else icon="mdi-shape-outline" title="Nessuna categoria" size="56" />
                </v-card>
            </v-tabs-window-item>

            <v-tabs-window-item value="vat">
                <v-card>
                    <v-card-item>
                        <v-card-title>Aliquote IVA</v-card-title>
                        <v-card-subtitle class="text-wrap">
                            Per le operazioni senza IVA indica la natura richiesta dalla fattura elettronica.
                        </v-card-subtitle>
                        <template #append>
                            <v-btn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openVat()">Nuova aliquota</v-btn>
                        </template>
                    </v-card-item>
                    <v-divider />
                    <v-data-table :headers="vatHeaders" :items="vatRates" :items-per-page="-1" hide-default-footer>
                        <template #[`item.name`]="{ item }">
                            <div class="py-2">
                                <div class="font-weight-medium">
                                    {{ item.name }}
                                    <v-chip v-if="item.is_default" color="primary" variant="tonal" class="ml-1">Predefinita</v-chip>
                                </div>
                                <div v-if="item.legal_reference" class="text-caption text-medium-emphasis">{{ item.legal_reference }}</div>
                            </div>
                        </template>
                        <template #[`item.rate`]="{ item }">{{ item.rate }}%</template>
                        <template #[`item.nature`]="{ item }">
                            <span :title="item.nature_label">{{ item.nature ?? '—' }}</span>
                        </template>
                        <template #[`item.is_active`]="{ item }">
                            <v-chip :color="item.is_active ? 'success' : undefined" variant="tonal">
                                {{ item.is_active ? 'Attiva' : 'Disattivata' }}
                            </v-chip>
                        </template>
                        <template #[`item.actions`]="{ item }">
                            <div class="d-flex justify-end">
                                <v-btn icon="mdi-pencil-outline" variant="text" size="small" title="Modifica" @click="openVat(item)" />
                                <v-btn
                                    v-if="!item.products_count && !item.is_default"
                                    icon="mdi-delete-outline"
                                    variant="text"
                                    size="small"
                                    color="error"
                                    title="Elimina"
                                    @click="deleteVat(item)"
                                />
                            </div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-tabs-window-item>
        </v-tabs-window>

        <v-dialog v-model="categoryDialog" max-width="480">
            <v-card :title="editingCategory ? 'Modifica categoria' : 'Nuova categoria'">
                <v-form @submit.prevent="saveCategory">
                    <v-card-text>
                        <v-text-field v-model="categoryForm.name" label="Nome" :error-messages="categoryForm.errors.name" autofocus class="mb-2" />
                        <div class="text-caption text-medium-emphasis mb-1">Colore</div>
                        <v-chip-group v-model="categoryForm.color" mandatory class="mb-2">
                            <v-chip v-for="color in colors" :key="color" :value="color" :color="color" variant="flat" filter>&nbsp;</v-chip>
                        </v-chip-group>
                        <v-textarea
                            v-model="categoryForm.description"
                            label="Descrizione"
                            rows="2"
                            auto-grow
                            :error-messages="categoryForm.errors.description"
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="categoryDialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" :loading="categoryForm.processing">Salva</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>

        <v-dialog v-model="vatDialog" max-width="560">
            <v-card :title="editingVat ? 'Modifica aliquota' : 'Nuova aliquota'">
                <v-form @submit.prevent="saveVat">
                    <v-card-text>
                        <v-row dense>
                            <v-col cols="12" sm="8">
                                <v-text-field
                                    v-model="vatForm.name"
                                    label="Nome"
                                    placeholder="Es. IVA 22%"
                                    :error-messages="vatForm.errors.name"
                                    autofocus
                                />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <v-text-field
                                    v-model="vatForm.rate"
                                    label="Aliquota"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.5"
                                    suffix="%"
                                    :error-messages="vatForm.errors.rate"
                                />
                            </v-col>
                            <template v-if="exempt">
                                <v-col cols="12">
                                    <v-autocomplete
                                        v-model="vatForm.nature"
                                        :items="natures"
                                        label="Natura (fattura elettronica)"
                                        :error-messages="vatForm.errors.nature"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field
                                        v-model="vatForm.legal_reference"
                                        label="Riferimento normativo"
                                        hint="Riportato in fattura, es. Esente art. 10 DPR 633/72"
                                        persistent-hint
                                        :error-messages="vatForm.errors.legal_reference"
                                    />
                                </v-col>
                            </template>
                            <v-col cols="12" sm="6">
                                <v-switch v-model="vatForm.is_default" label="Predefinita" hint="Proposta per i nuovi articoli" persistent-hint />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-switch v-model="vatForm.is_active" label="Attiva" :disabled="vatForm.is_default" />
                            </v-col>
                        </v-row>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn variant="text" @click="vatDialog = false">Annulla</v-btn>
                        <v-btn type="submit" color="primary" variant="flat" :loading="vatForm.processing">Salva</v-btn>
                    </v-card-actions>
                </v-form>
            </v-card>
        </v-dialog>
    </div>
</template>
