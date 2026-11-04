<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';

const props = defineProps({
    contract: { type: Object, default: null },
    defaults: { type: Object, default: null },
    customers: { type: Array, required: true },
    categories: { type: Array, required: true },
    modes: { type: Array, required: true },
    vatRates: { type: Array, required: true },
    products: { type: Array, required: true },
    managers: { type: Array, required: true },
});

const { link } = useInertiaLink();
const isNew = computed(() => !props.contract);
const source = props.contract ?? props.defaults;

const form = useForm({
    organization_id: source.organization_id,
    title: source.title ?? '',
    hours: source.hours,
    starts_on: source.starts_on,
    ends_on: source.ends_on,
    mode: source.mode ?? null,
    category_ids: source.category_ids ?? [],
    price: source.price ?? 0,
    product_id: source.product_id ?? null,
    vat_rate_id: source.vat_rate_id ?? null,
    manager_id: source.manager_id ?? null,
    is_active: source.is_active ?? true,
    notes: source.notes ?? '',
});

const modeItems = computed(() => [{ value: null, title: 'Remoto e on site' }, ...props.modes]);
const vatItems = computed(() => props.vatRates.map((rate) => ({ value: rate.id, title: rate.name })));
const hourly = computed(() => (Number(form.hours) > 0 ? Number(form.price || 0) / Number(form.hours) : 0));

function submit() {
    if (isNew.value) {
        form.post(route('interventions.contracts.store'));
    } else {
        form.put(route('interventions.contracts.update', props.contract.id));
    }
}
</script>

<template>
    <div>
        <PageHeader :title="isNew ? 'Nuovo contratto a monte ore' : `Contratto ${contract.number}`" subtitle="Ore prepagate dal cliente">
            <template #actions>
                <v-btn
                    variant="text"
                    prepend-icon="mdi-arrow-left"
                    v-bind="link(isNew ? route('interventions.contracts.index') : route('interventions.contracts.show', contract.id))"
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
                            <v-row dense>
                                <v-col cols="12">
                                    <v-autocomplete
                                        v-model="form.organization_id"
                                        :items="customers"
                                        label="Cliente"
                                        :error-messages="form.errors.organization_id"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field v-model="form.title" label="Descrizione" :error-messages="form.errors.title" />
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field
                                        v-model.number="form.hours"
                                        type="number"
                                        min="0"
                                        step="0.5"
                                        label="Ore acquistate"
                                        suffix="h"
                                        :error-messages="form.errors.hours"
                                    />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-text-field v-model="form.starts_on" type="date" label="Valido dal" :error-messages="form.errors.starts_on" />
                                </v-col>
                                <v-col cols="6" md="4">
                                    <v-text-field
                                        v-model="form.ends_on"
                                        type="date"
                                        label="Fino al"
                                        clearable
                                        hint="Vuoto: senza scadenza"
                                        persistent-hint
                                        :error-messages="form.errors.ends_on"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Interventi coperti">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="5">
                                    <v-select v-model="form.mode" :items="modeItems" label="Modalità" />
                                </v-col>
                                <v-col cols="12" md="7">
                                    <v-select
                                        v-model="form.category_ids"
                                        :items="categories"
                                        label="Categorie"
                                        multiple
                                        chips
                                        closable-chips
                                        hint="Nessuna scelta: tutte le categorie"
                                        persistent-hint
                                    />
                                </v-col>
                            </v-row>
                            <div class="text-caption text-medium-emphasis mt-2">
                                Le ore degli interventi coperti (arrotondate come in fattura) si scalano dal contratto; quando finiscono, l'eccedenza
                                si fattura normalmente. Uscita, km, viaggio e materiali si fatturano sempre.
                            </div>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Note">
                        <v-card-text>
                            <v-textarea v-model="form.notes" label="Note interne" rows="3" auto-grow hide-details />
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <v-card class="mb-6" title="Prezzo del pacchetto">
                        <v-card-text>
                            <v-text-field
                                v-model.number="form.price"
                                type="number"
                                step="0.01"
                                min="0"
                                label="Prezzo"
                                prefix="€"
                                :error-messages="form.errors.price"
                            />
                            <div v-if="hourly" class="text-caption text-medium-emphasis mt-n2 mb-3">
                                Pari a {{ hourly.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }) }} l'ora
                            </div>
                            <v-select v-model="form.vat_rate_id" :items="vatItems" label="IVA" />
                            <v-autocomplete
                                v-model="form.product_id"
                                :items="products"
                                label="Servizio del listino (facoltativo)"
                                clearable
                                hint="Per il codice articolo in fattura"
                                persistent-hint
                            />
                        </v-card-text>
                    </v-card>
                    <v-card class="mb-6">
                        <v-card-text>
                            <v-select
                                v-model="form.manager_id"
                                :items="managers"
                                label="Responsabile"
                                clearable
                                hint="Riceve l'avviso all'80% e a ore esaurite"
                                persistent-hint
                                class="mb-2"
                            />
                            <v-switch v-model="form.is_active" label="Contratto attivo" color="primary" inset hide-details />
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
