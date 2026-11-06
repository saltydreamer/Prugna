<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { formatMoney } from '@/utils/format';

/**
 * Hour package (contract a monte ore) sold with the order: invoiced with the order, the
 * interventions of the customer use it up. Works also on orders already invoiced.
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    order: { type: Object, required: true },
});

const lineId = ref(null);
const form = useForm({
    title: '',
    hours: 10,
    starts_on: null,
    ends_on: null,
    price: 0,
    product_id: null,
    vat_rate_id: null,
    apply_to_pending: true,
});

const lines = computed(() =>
    (props.order.lines ?? [])
        .filter((line) => line.type === 'item')
        .map((line) => ({
            ...line,
            label: `${firstLine(line.description)} · ${line.quantity} ${line.unit ?? ''} · ${formatMoney(line.total)}`,
        })),
);

function firstLine(text) {
    return (text ?? '').split('\n')[0].trim();
}

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.reset();
    form.clearErrors();
    form.starts_on = props.order.order_date ?? new Date().toISOString().slice(0, 10);
    // The service line in hours is most likely the package.
    const guess = lines.value.find((line) => line.unit === 'h') ?? (lines.value.length === 1 ? lines.value[0] : null);
    lineId.value = guess?.id ?? null;
    fromLine();
});

function fromLine() {
    const line = lines.value.find((item) => item.id === lineId.value);

    if (!line) {
        form.title = form.title || 'Pacchetto ore di assistenza';

        return;
    }

    form.title = firstLine(line.description).slice(0, 250) || 'Pacchetto ore di assistenza';
    form.hours = line.unit === 'h' ? line.quantity : form.hours;
    form.price = line.total;
    form.product_id = line.product_id;
    form.vat_rate_id = line.vat_rate_id;
}

function submit() {
    form.post(route('sales.orders.hour-packages.store', props.order.id), {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <v-dialog v-model="open" max-width="600">
        <v-card title="Aggiungi monte ore" :subtitle="`Pacchetto di ore venduto con l'ordine ${order.number}`">
            <v-card-text>
                <v-select
                    v-model="lineId"
                    :items="lines"
                    item-value="id"
                    item-title="label"
                    label="Riga dell'ordine con il pacchetto"
                    hint="Facoltativa: prende descrizione, ore e prezzo dalla riga"
                    persistent-hint
                    clearable
                    class="mb-3"
                    @update:model-value="fromLine"
                />
                <v-text-field v-model="form.title" label="Descrizione" :error-messages="form.errors.title" class="mb-2" />
                <v-row dense>
                    <v-col cols="12" sm="4">
                        <v-text-field
                            v-model.number="form.hours"
                            label="Ore"
                            type="number"
                            min="0.25"
                            step="0.5"
                            suffix="h"
                            :error-messages="form.errors.hours"
                        />
                    </v-col>
                    <v-col cols="6" sm="4">
                        <v-text-field v-model="form.starts_on" label="Valido dal" type="date" :error-messages="form.errors.starts_on" />
                    </v-col>
                    <v-col cols="6" sm="4">
                        <v-text-field
                            v-model="form.ends_on"
                            label="Scadenza"
                            type="date"
                            clearable
                            hint="Vuota: senza scadenza"
                            persistent-hint
                            :error-messages="form.errors.ends_on"
                        />
                    </v-col>
                </v-row>
                <v-checkbox
                    v-model="form.apply_to_pending"
                    label="Scala subito gli interventi del cliente ancora da fatturare da questa data"
                    hide-details
                    class="mt-2"
                />
                <div class="text-caption text-medium-emphasis mt-2">
                    Il pacchetto si fattura con l'ordine (prezzo {{ formatMoney(form.price || 0) }}): non viene mai fatturato da solo. Gli interventi
                    successivi del cliente scalano le ore in automatico.
                </div>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-timer-plus-outline" :loading="form.processing" @click="submit">
                    Crea monte ore
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
