<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { http } from '@/utils/http';

/**
 * "Avvia intervento": starts the timer now for a customer and a category (made for the phone).
 */
const open = defineModel({ type: Boolean, default: false });

const props = defineProps({
    organizationId: { type: Number, default: null },
    orderId: { type: Number, default: null },
});

const options = reactive({ customers: [], categories: [], modes: [] });
const customer = reactive({ locations: [], orders: [] });
const form = reactive({ organization_id: null, category_id: null, mode: 'remote', location_id: null, order_id: null, description: '' });
const errors = ref({});
const loading = ref(false);
const saving = ref(false);

watch(open, async (value) => {
    if (!value) {
        return;
    }

    errors.value = {};
    Object.assign(form, { organization_id: props.organizationId, order_id: props.orderId, location_id: null, description: '' });

    if (!options.customers.length) {
        loading.value = true;

        try {
            Object.assign(options, await http.get(route('interventions.start-options')));
        } finally {
            loading.value = false;
        }
    }

    form.category_id ??= options.categories[0]?.value ?? null;
    loadCustomer();
});

async function loadCustomer() {
    customer.locations = [];
    customer.orders = [];

    if (!form.organization_id) {
        return;
    }

    const data = await http.get(route('interventions.customer', form.organization_id));
    customer.locations = data.locations;
    customer.orders = data.orders;
    form.location_id = data.locations[0]?.value ?? null;
}

watch(
    () => form.organization_id,
    (value, previous) => {
        if (previous !== undefined && value !== previous && open.value) {
            form.order_id = null;
            loadCustomer();
        }
    },
);

function start() {
    saving.value = true;
    router.post(
        route('interventions.start'),
        { ...form },
        {
            onError: (bag) => (errors.value = bag),
            onSuccess: () => (open.value = false),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <v-dialog v-model="open" max-width="520" :fullscreen="$vuetify.display.xs">
        <v-card title="Avvia intervento" subtitle="Il tempo parte adesso: lo fermi con «Termina»." :loading="loading">
            <v-card-text>
                <v-alert v-if="!loading && !options.categories.length" type="warning" variant="tonal" density="compact" class="mb-4">
                    Nessuna categoria di intervento: creala prima in Impostazioni interventi.
                </v-alert>
                <v-autocomplete
                    v-model="form.organization_id"
                    :items="options.customers"
                    label="Cliente"
                    :error-messages="errors.organization_id"
                    autofocus
                    class="mb-2"
                />
                <v-select
                    v-model="form.category_id"
                    :items="options.categories"
                    label="Categoria"
                    :error-messages="errors.category_id"
                    class="mb-2"
                />
                <v-btn-toggle v-model="form.mode" mandatory color="primary" variant="outlined" divided class="mb-4 w-100">
                    <v-btn v-for="mode in options.modes" :key="mode.value" :value="mode.value" :prepend-icon="mode.icon" class="flex-grow-1">
                        {{ mode.title }}
                    </v-btn>
                </v-btn-toggle>
                <v-select
                    v-if="form.mode === 'onsite' && customer.locations.length"
                    v-model="form.location_id"
                    :items="customer.locations"
                    label="Sede"
                    clearable
                    class="mb-2"
                />
                <v-select
                    v-if="customer.orders.length"
                    v-model="form.order_id"
                    :items="customer.orders"
                    label="Ordine (facoltativo)"
                    clearable
                    class="mb-2"
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :subtitle="item.subtitle" />
                    </template>
                </v-select>
                <v-textarea v-model="form.description" label="Cosa devi fare (facoltativo)" rows="2" auto-grow />
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="open = false">Annulla</v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-play"
                    :loading="saving"
                    :disabled="!form.organization_id || !form.category_id"
                    @click="start"
                >
                    Avvia
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>
