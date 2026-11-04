<script setup>
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { formatMoney } from '@/utils/format';

const props = defineProps({
    categories: { type: Array, required: true },
    settings: { type: Object, required: true },
    products: { type: Array, required: true },
    roundingOptions: { type: Array, required: true },
});

const { confirm } = useConfirm();

// --- General settings ------------------------------------------------------------------------
const extras = [
    { key: 'callout', title: 'Diritto di uscita', hint: 'Importo fisso per ogni intervento on site con uscita', unit: '€ a uscita' },
    { key: 'km', title: 'Rimborso chilometrico', hint: 'Prezzo per km percorso', unit: '€ al km' },
    { key: 'travel', title: 'Tempo di viaggio', hint: 'Tariffa oraria del viaggio (arrotondato come il lavoro)', unit: "€ l'ora" },
];

const settingsForm = useForm({
    rounding_minutes: props.settings.rounding_minutes,
    callout_product_id: props.settings.callout_product_id,
    km_product_id: props.settings.km_product_id,
    travel_product_id: props.settings.travel_product_id,
    callout_new_price: null,
    km_new_price: null,
    travel_new_price: null,
});
const createExtra = reactive({ callout: false, km: false, travel: false });

function saveSettings() {
    settingsForm
        .transform((data) => {
            const payload = { ...data };

            extras.forEach(({ key }) => {
                if (createExtra[key]) {
                    payload[`${key}_product_id`] = null;
                } else {
                    payload[`${key}_new_price`] = null;
                }
            });

            return payload;
        })
        .put(route('interventions.settings.update'), {
            preserveScroll: true,
            onSuccess: () => {
                extras.forEach(({ key }) => (createExtra[key] = false));
                settingsForm.defaults({ ...props.settings, callout_new_price: null, km_new_price: null, travel_new_price: null });
                settingsForm.reset();
            },
        });
}

// --- Categories ------------------------------------------------------------------------------
const dialog = ref(false);
const editing = ref(null);
const createService = reactive({ remote: false, onsite: false });
const categoryForm = useForm({
    name: '',
    remote_product_id: null,
    onsite_product_id: null,
    remote_new_price: null,
    onsite_new_price: null,
    is_active: true,
    sort: 0,
});

function openCategory(category = null) {
    editing.value = category;
    categoryForm.clearErrors();
    Object.assign(categoryForm, {
        name: category?.name ?? '',
        remote_product_id: category?.remote_product_id ?? null,
        onsite_product_id: category?.onsite_product_id ?? null,
        remote_new_price: null,
        onsite_new_price: null,
        is_active: category?.is_active ?? true,
        sort: category?.sort ?? props.categories.length,
    });
    createService.remote = !category;
    createService.onsite = !category;
    dialog.value = true;
}

function saveCategory() {
    const options = { preserveScroll: true, onSuccess: () => (dialog.value = false) };
    const transform = (data) => ({
        ...data,
        remote_product_id: createService.remote ? null : data.remote_product_id,
        onsite_product_id: createService.onsite ? null : data.onsite_product_id,
        remote_new_price: createService.remote ? data.remote_new_price : null,
        onsite_new_price: createService.onsite ? data.onsite_new_price : null,
    });

    if (editing.value) {
        categoryForm.transform(transform).put(route('interventions.settings.categories.update', editing.value.id), options);
    } else {
        categoryForm.transform(transform).post(route('interventions.settings.categories.store'), options);
    }
}

async function destroyCategory(category) {
    const ok = await confirm({
        title: `Eliminare la categoria «${category.name}»?`,
        message: category.interventions_count ? 'È usata da alcuni interventi: verrà solo disattivata.' : '',
        confirmText: category.interventions_count ? 'Disattiva' : 'Elimina',
        color: 'error',
    });

    if (ok) {
        router.delete(route('interventions.settings.categories.destroy', category.id), { preserveScroll: true });
    }
}

function productLabel(product) {
    return product ? `${product.name} · ${formatMoney(product.price)}/${product.unit}` : null;
}
</script>

<template>
    <div>
        <PageHeader title="Impostazioni interventi" subtitle="Categorie, tariffe, arrotondamento e voci extra degli interventi on site" />

        <v-card class="mb-6">
            <v-card-item>
                <v-card-title>Categorie e tariffe</v-card-title>
                <v-card-subtitle class="text-wrap">
                    Ogni categoria si fattura con un servizio del listino per il lavoro da remoto e uno per l'on site: valgono anche i prezzi
                    concordati con i clienti.
                </v-card-subtitle>
                <template #append>
                    <v-btn color="primary" prepend-icon="mdi-plus" @click="openCategory()">Nuova categoria</v-btn>
                </template>
            </v-card-item>
            <v-table v-if="categories.length">
                <thead>
                    <tr>
                        <th>Categoria</th>
                        <th>Da remoto</th>
                        <th>On site</th>
                        <th class="text-right">Interventi</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories" :key="category.id" :class="{ 'text-medium-emphasis': !category.is_active }">
                        <td class="font-weight-medium">
                            {{ category.name }}
                            <v-chip v-if="!category.is_active" size="x-small" class="ml-1">disattivata</v-chip>
                        </td>
                        <td>
                            <span v-if="category.remote_product">{{ productLabel(category.remote_product) }}</span>
                            <span v-else class="text-warning">da impostare</span>
                        </td>
                        <td>
                            <span v-if="category.onsite_product">{{ productLabel(category.onsite_product) }}</span>
                            <span v-else class="text-warning">da impostare</span>
                        </td>
                        <td class="text-right">{{ category.interventions_count }}</td>
                        <td class="text-right text-no-wrap">
                            <v-btn icon="mdi-pencil-outline" size="small" variant="text" title="Modifica" @click="openCategory(category)" />
                            <v-btn
                                icon="mdi-delete-outline"
                                size="small"
                                variant="text"
                                color="error"
                                title="Elimina"
                                @click="destroyCategory(category)"
                            />
                        </td>
                    </tr>
                </tbody>
            </v-table>
            <v-card-text v-else>
                <v-empty-state
                    icon="mdi-shape-outline"
                    title="Nessuna categoria"
                    text="Crea almeno una categoria (es. «Assistenza sistemistica») con le tariffe orarie per poter registrare gli interventi."
                    size="48"
                />
            </v-card-text>
        </v-card>

        <v-card>
            <v-card-item>
                <v-card-title>Calcolo e voci extra</v-card-title>
            </v-card-item>
            <v-card-text>
                <v-select
                    v-model="settingsForm.rounding_minutes"
                    :items="roundingOptions"
                    label="Arrotondamento del tempo (per eccesso)"
                    hint="Vale per il lavoro e per il viaggio di ogni intervento"
                    persistent-hint
                    style="max-width: 420px"
                    class="mb-6"
                    :error-messages="settingsForm.errors.rounding_minutes"
                />

                <v-row v-for="extra in extras" :key="extra.key" dense class="mb-2">
                    <v-col cols="12" md="4">
                        <div class="text-subtitle-2">{{ extra.title }}</div>
                        <div class="text-caption text-medium-emphasis">{{ extra.hint }}</div>
                    </v-col>
                    <v-col cols="12" md="8">
                        <div class="d-flex flex-wrap align-center ga-2">
                            <v-autocomplete
                                v-if="!createExtra[extra.key]"
                                v-model="settingsForm[`${extra.key}_product_id`]"
                                :items="products"
                                label="Servizio del listino"
                                clearable
                                density="compact"
                                hide-details="auto"
                                class="flex-grow-1"
                                style="min-width: 240px"
                                :error-messages="settingsForm.errors[`${extra.key}_product_id`]"
                            >
                                <template #item="{ props: itemProps, item }">
                                    <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                                </template>
                            </v-autocomplete>
                            <v-text-field
                                v-else
                                v-model.number="settingsForm[`${extra.key}_new_price`]"
                                type="number"
                                step="0.01"
                                min="0"
                                :label="`Prezzo (${extra.unit})`"
                                prefix="€"
                                density="compact"
                                hide-details="auto"
                                class="flex-grow-1"
                                style="min-width: 200px"
                                :error-messages="settingsForm.errors[`${extra.key}_new_price`]"
                            />
                            <v-btn size="small" variant="text" @click="createExtra[extra.key] = !createExtra[extra.key]">
                                {{ createExtra[extra.key] ? 'Scegli dal listino' : 'Crea nuovo servizio' }}
                            </v-btn>
                        </div>
                    </v-col>
                </v-row>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn color="primary" variant="flat" :loading="settingsForm.processing" @click="saveSettings">Salva</v-btn>
            </v-card-actions>
        </v-card>

        <v-dialog v-model="dialog" max-width="560">
            <v-card :title="editing ? 'Modifica categoria' : 'Nuova categoria'">
                <v-card-text>
                    <v-text-field v-model="categoryForm.name" label="Nome" autofocus :error-messages="categoryForm.errors.name" class="mb-2" />

                    <div
                        v-for="mode in [
                            { key: 'remote', title: 'Tariffa da remoto' },
                            { key: 'onsite', title: 'Tariffa on site' },
                        ]"
                        :key="mode.key"
                        class="mb-4"
                    >
                        <div class="d-flex align-center mb-1">
                            <span class="text-subtitle-2">{{ mode.title }}</span>
                            <v-spacer />
                            <v-btn size="small" variant="text" @click="createService[mode.key] = !createService[mode.key]">
                                {{ createService[mode.key] ? 'Scegli dal listino' : 'Crea nuovo servizio' }}
                            </v-btn>
                        </div>
                        <v-text-field
                            v-if="createService[mode.key]"
                            v-model.number="categoryForm[`${mode.key}_new_price`]"
                            type="number"
                            step="0.01"
                            min="0"
                            label="Prezzo orario"
                            prefix="€"
                            suffix="/ h"
                            :hint="`Crea il servizio «${categoryForm.name || 'Categoria'} ${mode.key === 'remote' ? 'da remoto' : 'on site'}» nel listino`"
                            persistent-hint
                            :error-messages="categoryForm.errors[`${mode.key}_new_price`]"
                        />
                        <v-autocomplete
                            v-else
                            v-model="categoryForm[`${mode.key}_product_id`]"
                            :items="products"
                            label="Servizio del listino"
                            clearable
                            :error-messages="categoryForm.errors[`${mode.key}_product_id`]"
                        >
                            <template #item="{ props: itemProps, item }">
                                <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                            </template>
                        </v-autocomplete>
                    </div>

                    <v-switch v-model="categoryForm.is_active" label="Attiva" color="primary" inset hide-details />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog = false">Annulla</v-btn>
                    <v-btn color="primary" variant="flat" :loading="categoryForm.processing" @click="saveCategory">Salva</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>
