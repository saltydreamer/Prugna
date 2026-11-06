<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { estimateTotals, itemCost, markupFor } from '@/utils/estimateTotals';
import { formatMoney, formatPercent } from '@/utils/format';
import { http, upload } from '@/utils/http';

const props = defineProps({
    estimate: { type: Object, default: null },
    customers: { type: Array, required: true },
    categorySuggestions: { type: Array, default: () => [] },
    canUseAi: { type: Boolean, default: false },
    vatFree: { type: Boolean, default: false },
    canAccept: { type: Boolean, default: false },
});

const { link } = useInertiaLink();
const { confirm } = useConfirm();

const isNew = computed(() => !props.estimate);
const accepted = computed(() => props.estimate?.status === 'accepted');
const source = props.estimate ?? {};

let uid = 0;
const key = () => `item-${Date.now()}-${uid++}`;

const form = useForm({
    organization_id: source.organization_id ?? null,
    title: source.title ?? '',
    intro: source.intro ?? '',
    months: source.months ?? 36,
    markup_percent: source.markup_percent ?? 30,
    category_markups: { ...(source.category_markups ?? {}) },
    upfront: source.upfront ?? 0,
    starts_on: source.starts_on ?? null,
    advantages: source.advantages ?? '',
    notes: source.notes ?? '',
    items: (source.items ?? []).map((item) => ({ ...item, key: key() })),
});

const totals = computed(() => estimateTotals(form));
const categoryNames = computed(() => [...new Set([...form.items.map((item) => item.category).filter(Boolean), ...props.categorySuggestions])]);

/** Items grouped by category, in the order the categories first appear. */
const groups = computed(() => {
    const map = new Map();

    form.items.forEach((item, index) => {
        const name = item.category || '';
        map.set(name, [...(map.get(name) ?? []), { item, index }]);
    });

    return [...map.entries()].map(([name, rows]) => ({
        name,
        rows,
        totals: totals.value.categories.find((category) => (category.name || '') === name),
    }));
});

function addItem(item = {}) {
    const lastCategory = form.items.at(-1)?.category ?? null;
    form.items.push({ key: key(), category: lastCategory, description: '', url: null, quantity: 1, unit_cost: 0, note: null, ...item });
}

function removeItem(index) {
    form.items.splice(index, 1);
}

function itemPrice(item) {
    return itemCost(item) * (1 + markupFor(form, item.category) / 100);
}

function setCategoryMarkup(name, value) {
    const markups = { ...form.category_markups };

    if (value === '' || value === null || value === undefined) {
        delete markups[name];
    } else {
        markups[name] = Number(value);
    }

    form.category_markups = markups;
}

function error(index, field) {
    return form.errors[`items.${index}.${field}`];
}

function submit() {
    const transform = (data) => ({ ...data, items: data.items.map(({ key: _key, id: _id, ...item }) => item) }); // eslint-disable-line no-unused-vars

    if (isNew.value) {
        form.transform(transform).post(route('sales.estimates.store'));
    } else {
        form.transform(transform).put(route('sales.estimates.update', props.estimate.id), { preserveScroll: true });
    }
}

// Item from a link or a screenshot: the AI reads name and price of the product page.
// Screenshots work with the shops that block automatic reading (Amazon) and with logged-in prices.
const linkDialog = ref(false);
const linkMode = ref('link');
const linkForm = ref({ url: '', category: null, quantity: 1 });
const linkResult = ref(null);
const linkLoading = ref(false);
const linkError = ref('');
const linkImage = ref(null);
const linkPreview = ref(null);
const imageInput = ref(null);
const dragging = ref(false);

function openLink(mode = 'link') {
    linkForm.value = { url: '', category: form.items.at(-1)?.category ?? null, quantity: 1 };
    linkMode.value = mode;
    setImage(null);
    resetLinkResult();
    linkDialog.value = true;
}

function resetLinkResult() {
    linkResult.value = null;
    linkError.value = '';
}

function switchMode(mode) {
    linkMode.value = mode;
    resetLinkResult();
}

function setImage(file) {
    if (linkPreview.value) {
        URL.revokeObjectURL(linkPreview.value);
    }

    linkImage.value = file;
    linkPreview.value = file ? URL.createObjectURL(file) : null;
    resetLinkResult();
}

function pickImage(files) {
    const file = [...(files ?? [])].find((item) => item.type.startsWith('image/'));

    if (!file) {
        return false;
    }

    if (file.size > 10 * 1024 * 1024) {
        linkMode.value = 'image';
        linkError.value = "L'immagine supera 10 MB: cattura solo la parte con nome e prezzo.";

        return true;
    }

    linkMode.value = 'image';
    setImage(file);

    return true;
}

// Ctrl+V anywhere in the dialog: an image in the clipboard goes to the screenshot tab.
function onPaste(event) {
    if (props.canUseAi && pickImage(event.clipboardData?.files)) {
        event.preventDefault();
    }
}

function onDrop(event) {
    dragging.value = false;
    pickImage(event.dataTransfer?.files);
}

async function readLink() {
    linkLoading.value = true;
    resetLinkResult();

    try {
        const { data } = await http.post(route('sales.estimates.read-link'), { url: linkForm.value.url });
        linkResult.value = data;
    } catch (exception) {
        linkError.value = exception.errors?.url?.[0] ?? exception.message;
    } finally {
        linkLoading.value = false;
    }
}

async function readImage() {
    linkLoading.value = true;
    resetLinkResult();

    const data = new FormData();
    data.append('image', linkImage.value, linkImage.value.name || 'screenshot.png');

    if (linkForm.value.url) {
        data.append('url', linkForm.value.url);
    }

    try {
        linkResult.value = (await upload(route('sales.estimates.read-image'), data)).data;
    } catch (exception) {
        linkError.value =
            exception.errors?.image?.[0] ??
            exception.errors?.url?.[0] ??
            (exception.status === 413 ? "L'immagine è troppo grande." : exception.message);
    } finally {
        linkLoading.value = false;
    }
}

function addFromLink() {
    addItem({
        category: linkForm.value.category,
        description: linkResult.value.description,
        url: linkResult.value.url,
        quantity: Number(linkForm.value.quantity) || 1,
        unit_cost: linkResult.value.unit_cost ?? 0,
        note: linkResult.value.note,
    });
    linkDialog.value = false;
    setImage(null);
}

// Advantages drafted by the AI from the saved proposal.
const advantagesLoading = ref(false);
const advantagesError = ref('');

async function draftAdvantages() {
    if (form.isDirty) {
        advantagesError.value = "Salva prima la proposta: l'IA legge gli articoli salvati.";

        return;
    }

    if (
        form.advantages &&
        !(await confirm({
            title: 'Sostituire i vantaggi?',
            message: "Il testo attuale verrà sostituito dalla proposta dell'IA.",
            confirmText: 'Sostituisci',
        }))
    ) {
        return;
    }

    advantagesLoading.value = true;
    advantagesError.value = '';

    try {
        const { data } = await http.post(route('sales.estimates.advantages', props.estimate.id));
        form.advantages = data;
    } catch (exception) {
        advantagesError.value = exception.errors?.advantages?.[0] ?? exception.message;
    } finally {
        advantagesLoading.value = false;
    }
}

async function accept() {
    const ok = await confirm({
        title: 'Il cliente ha accettato?',
        message: `Viene creato un contratto servizi di ${totals.value.months} mesi a ${formatMoney(totals.value.monthly)} al mese${totals.value.upfront > 0 ? ` e la bozza di fattura dell'anticipo (${formatMoney(totals.value.upfront)})` : ''}. La proposta non sarà più modificabile.`,
        confirmText: 'Crea il contratto',
    });

    if (ok) {
        router.post(route('sales.estimates.accept', props.estimate.id));
    }
}

async function destroy() {
    if (await confirm({ title: `Eliminare la proposta ${props.estimate.number}?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('sales.estimates.destroy', props.estimate.id));
    }
}
</script>

<template>
    <div>
        <PageHeader
            :title="isNew ? 'Nuova proposta' : `Proposta ${estimate.number}`"
            subtitle="Articoli a costo, ricarico e canone mensile sulla durata del contratto"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route('sales.estimates.index'))">Preventivatore</v-btn>
                <v-btn
                    v-if="!isNew"
                    variant="tonal"
                    prepend-icon="mdi-file-pdf-box"
                    :href="route('sales.estimates.pdf', estimate.id)"
                    target="_blank"
                    :disabled="form.isDirty"
                    :title="form.isDirty ? 'Salva prima di generare il report' : ''"
                >
                    Report per il cliente
                </v-btn>
                <v-btn
                    v-if="!isNew && !accepted && canAccept"
                    color="success"
                    variant="flat"
                    prepend-icon="mdi-handshake-outline"
                    :disabled="form.isDirty || !estimate.organization_id || totals.monthly <= 0"
                    @click="accept"
                >
                    Accettata
                </v-btn>
                <v-btn v-if="!isNew && !accepted" variant="text" color="error" icon="mdi-delete-outline" title="Elimina" @click="destroy" />
            </template>
        </PageHeader>

        <v-alert v-if="accepted" type="success" variant="tonal" class="mb-6" icon="mdi-handshake-outline">
            Accettata: è diventata il contratto servizi
            <a v-if="estimate.subscription" v-bind="link(route('sales.subscriptions.show', estimate.subscription.id))" class="font-weight-medium">
                {{ estimate.subscription.number }}
            </a>
            . La proposta resta come riferimento e non si modifica.
        </v-alert>

        <v-form :disabled="accepted" @submit.prevent="submit">
            <v-row>
                <v-col cols="12" lg="8">
                    <v-card class="mb-6" title="Proposta">
                        <v-card-text>
                            <v-row dense>
                                <v-col cols="12" md="6">
                                    <v-autocomplete
                                        v-model="form.organization_id"
                                        :items="customers"
                                        label="Cliente"
                                        clearable
                                        :error-messages="form.errors.organization_id"
                                    />
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="form.title"
                                        label="Titolo"
                                        placeholder="Es. Infrastruttura server e rete"
                                        :error-messages="form.errors.title"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-textarea
                                        v-model="form.intro"
                                        label="Introduzione per il cliente"
                                        rows="2"
                                        auto-grow
                                        :error-messages="form.errors.intro"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6">
                        <v-card-item>
                            <v-card-title>Articoli</v-card-title>
                            <v-card-subtitle>
                                Costo unitario {{ vatFree ? 'IVA inclusa (nel forfettario non la detrai)' : 'IVA esclusa' }}; il prezzo al cliente
                                aggiunge il ricarico della categoria.
                            </v-card-subtitle>
                        </v-card-item>

                        <div v-if="!form.items.length" class="text-center text-medium-emphasis py-6">
                            Nessun articolo: aggiungilo a mano o incolla il link della pagina del prodotto.
                        </div>

                        <div v-for="group in groups" :key="group.name" class="estimate-group">
                            <div class="estimate-group__header d-flex flex-wrap align-center ga-3 px-4 py-2">
                                <span class="font-weight-medium flex-grow-1">{{ group.name || 'Senza categoria' }}</span>
                                <v-text-field
                                    v-if="group.name"
                                    :model-value="form.category_markups[group.name] ?? ''"
                                    :placeholder="String(form.markup_percent)"
                                    label="Ricarico"
                                    type="number"
                                    suffix="%"
                                    density="compact"
                                    hide-details
                                    persistent-placeholder
                                    style="max-width: 120px"
                                    @update:model-value="(value) => setCategoryMarkup(group.name, value)"
                                />
                                <span class="text-body-2 text-medium-emphasis text-no-wrap">
                                    costo {{ formatMoney(group.totals?.cost ?? 0) }} · prezzo
                                    <strong class="text-high-emphasis">{{ formatMoney(group.totals?.price ?? 0) }}</strong>
                                </span>
                            </div>
                            <div v-for="{ item, index } in group.rows" :key="item.key" class="estimate-item px-4 py-2">
                                <v-row dense align="center">
                                    <v-col cols="12">
                                        <v-text-field
                                            v-model="item.description"
                                            label="Articolo"
                                            density="compact"
                                            hide-details="auto"
                                            :error-messages="error(index, 'description')"
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="4">
                                        <v-combobox
                                            v-model="item.category"
                                            :items="categoryNames"
                                            label="Categoria"
                                            density="compact"
                                            hide-details
                                            clearable
                                        />
                                    </v-col>
                                    <v-col cols="4" sm="2">
                                        <v-text-field
                                            v-model.number="item.quantity"
                                            label="Q.tà"
                                            type="number"
                                            min="0"
                                            step="any"
                                            density="compact"
                                            hide-details="auto"
                                            :error-messages="error(index, 'quantity')"
                                        />
                                    </v-col>
                                    <v-col cols="8" sm="3">
                                        <v-text-field
                                            v-model.number="item.unit_cost"
                                            label="Costo unit."
                                            type="number"
                                            step="0.01"
                                            prefix="€"
                                            density="compact"
                                            hide-details="auto"
                                            :error-messages="error(index, 'unit_cost')"
                                        />
                                    </v-col>
                                    <v-col cols="12" sm="3" class="d-flex align-center justify-end ga-1">
                                        <div class="text-right text-no-wrap">
                                            <div class="font-weight-medium">{{ formatMoney(itemPrice(item)) }}</div>
                                            <div class="text-caption text-medium-emphasis">costo {{ formatMoney(itemCost(item)) }}</div>
                                        </div>
                                        <v-btn
                                            v-if="item.url"
                                            icon="mdi-open-in-new"
                                            size="x-small"
                                            variant="text"
                                            :href="item.url"
                                            target="_blank"
                                            rel="noopener"
                                            title="Apri la pagina del prodotto"
                                        />
                                        <v-btn
                                            v-if="!accepted"
                                            icon="mdi-close"
                                            size="x-small"
                                            variant="text"
                                            title="Togli"
                                            @click="removeItem(index)"
                                        />
                                    </v-col>
                                </v-row>
                                <div v-if="item.note" class="text-caption text-medium-emphasis mt-1">
                                    <v-icon icon="mdi-robot-outline" size="12" />
                                    {{ item.note }}
                                </div>
                            </div>
                        </div>

                        <v-card-actions v-if="!accepted">
                            <v-btn variant="tonal" prepend-icon="mdi-plus" @click="addItem()">Articolo</v-btn>
                            <v-btn variant="tonal" prepend-icon="mdi-link-variant" @click="openLink('link')">Da link</v-btn>
                            <v-btn v-if="canUseAi" variant="tonal" prepend-icon="mdi-monitor-screenshot" @click="openLink('image')">
                                Da screenshot
                            </v-btn>
                        </v-card-actions>
                    </v-card>

                    <v-card class="mb-6">
                        <v-card-item>
                            <v-card-title>Vantaggi della soluzione</v-card-title>
                            <v-card-subtitle>Uno per riga: vanno nel report per il cliente</v-card-subtitle>
                            <template v-if="!isNew && !accepted && canUseAi" #append>
                                <v-btn variant="tonal" prepend-icon="mdi-auto-fix" :loading="advantagesLoading" @click="draftAdvantages">
                                    Proponi con l'IA
                                </v-btn>
                            </template>
                        </v-card-item>
                        <v-card-text>
                            <v-alert v-if="advantagesError" type="warning" variant="tonal" density="compact" class="mb-3">
                                {{ advantagesError }}
                            </v-alert>
                            <v-textarea
                                v-model="form.advantages"
                                rows="4"
                                auto-grow
                                placeholder="Nessun investimento iniziale&#10;Canone fisso per 36 mesi: costi prevedibili"
                                hide-details="auto"
                                :error-messages="form.errors.advantages"
                            />
                        </v-card-text>
                    </v-card>

                    <v-card class="mb-6" title="Condizioni">
                        <v-card-text>
                            <v-textarea v-model="form.notes" rows="2" auto-grow label="Condizioni per il cliente (nel report)" hide-details="auto" />
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <div class="estimate-sidebar">
                        <v-card class="mb-4" title="Piano">
                            <v-card-text>
                                <v-row dense>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model.number="form.markup_percent"
                                            label="Ricarico generale"
                                            type="number"
                                            suffix="%"
                                            :error-messages="form.errors.markup_percent"
                                        />
                                    </v-col>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model.number="form.months"
                                            label="Durata"
                                            type="number"
                                            suffix="mesi"
                                            :error-messages="form.errors.months"
                                        />
                                    </v-col>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model.number="form.upfront"
                                            label="Anticipo"
                                            type="number"
                                            step="0.01"
                                            prefix="€"
                                            :error-messages="form.errors.upfront"
                                        />
                                    </v-col>
                                    <v-col cols="6">
                                        <v-text-field
                                            v-model="form.starts_on"
                                            label="Decorrenza"
                                            type="date"
                                            clearable
                                            hint="Vuota: dal mese dopo il sì"
                                            persistent-hint
                                        />
                                    </v-col>
                                </v-row>

                                <v-table density="compact" class="mt-3">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis">Costo articoli</td>
                                            <td class="text-right">{{ formatMoney(totals.cost) }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis">Prezzo al cliente</td>
                                            <td class="text-right">{{ formatMoney(totals.price) }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis">Margine</td>
                                            <td class="text-right">{{ formatMoney(totals.margin) }} · {{ formatPercent(totals.marginPercent) }}</td>
                                        </tr>
                                        <tr v-if="totals.upfront">
                                            <td class="text-medium-emphasis">Anticipo</td>
                                            <td class="text-right">{{ formatMoney(totals.upfront) }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis">Totale contratto</td>
                                            <td class="text-right">{{ formatMoney(totals.contractTotal) }}</td>
                                        </tr>
                                    </tbody>
                                </v-table>
                                <div class="text-center mt-4">
                                    <div class="text-caption text-medium-emphasis">Canone mensile per {{ totals.months }} mesi</div>
                                    <div class="text-h5 font-weight-bold text-primary">{{ formatMoney(totals.monthly) }}</div>
                                    <div class="text-caption text-medium-emphasis">{{ vatFree ? 'senza IVA (forfettario)' : 'IVA esclusa' }}</div>
                                </div>
                            </v-card-text>
                            <v-card-actions v-if="!accepted">
                                <v-btn type="submit" color="primary" variant="flat" block size="large" :loading="form.processing">
                                    {{ isNew ? 'Crea proposta' : 'Salva' }}
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                        <div v-if="!isNew && !accepted && !estimate.organization_id" class="text-caption text-medium-emphasis px-2">
                            Scegli il cliente per poter segnare la proposta come accettata.
                        </div>
                    </div>
                </v-col>
            </v-row>
        </v-form>

        <v-dialog v-model="linkDialog" max-width="600">
            <v-card title="Articolo dal negozio" subtitle="Nome e prezzo del prodotto li legge l'IA" @paste="onPaste">
                <v-tabs :model-value="linkMode" density="compact" class="px-4" @update:model-value="switchMode">
                    <v-tab value="link" prepend-icon="mdi-link-variant">Link</v-tab>
                    <v-tab value="image" prepend-icon="mdi-monitor-screenshot">Screenshot</v-tab>
                </v-tabs>
                <v-card-text>
                    <template v-if="linkMode === 'link'">
                        <v-text-field
                            v-model="linkForm.url"
                            label="Link"
                            placeholder="https://"
                            prepend-inner-icon="mdi-link-variant"
                            :error-messages="linkError"
                            autofocus
                            @keydown.enter.prevent="readLink"
                        />
                        <div v-if="linkError && canUseAi" class="mb-3">
                            <v-btn size="small" variant="tonal" prepend-icon="mdi-monitor-screenshot" @click="switchMode('image')">
                                Usa uno screenshot
                            </v-btn>
                        </div>
                        <div v-if="!canUseAi" class="text-caption text-medium-emphasis mb-2">
                            L'IA non è attiva: leggo solo i prezzi che il sito pubblica in modo strutturato.
                        </div>
                    </template>
                    <template v-else>
                        <v-alert v-if="!canUseAi" type="info" variant="tonal" density="compact" class="mb-3">
                            Per leggere gli screenshot serve l'IA attiva (Impostazioni → AI).
                        </v-alert>
                        <template v-else>
                            <div
                                class="paste-zone mb-3"
                                :class="{ 'paste-zone--active': dragging }"
                                role="button"
                                tabindex="0"
                                @click="imageInput.click()"
                                @keydown.enter.prevent="imageInput.click()"
                                @dragover.prevent="dragging = true"
                                @dragleave="dragging = false"
                                @drop.prevent="onDrop"
                            >
                                <img v-if="linkPreview" :src="linkPreview" alt="Screenshot del prodotto" class="paste-zone__image" />
                                <div v-else class="text-center pa-6">
                                    <v-icon icon="mdi-monitor-screenshot" size="32" class="mb-2 text-medium-emphasis" />
                                    <div class="text-body-2">
                                        Incolla lo screenshot con
                                        <strong>Ctrl+V</strong>
                                        , trascinalo qui o clicca per sceglierlo
                                    </div>
                                    <div class="text-caption text-medium-emphasis mt-1">
                                        Cattura la parte della pagina con nome e prezzo: su Windows Win+Maiusc+S, su Mac Cmd+Ctrl+Maiusc+4.
                                    </div>
                                </div>
                            </div>
                            <input
                                ref="imageInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                hidden
                                @change="
                                    pickImage($event.target.files);
                                    $event.target.value = '';
                                "
                            />
                            <v-alert v-if="linkError" type="error" variant="tonal" density="compact" class="mb-3">{{ linkError }}</v-alert>
                            <v-text-field
                                v-model="linkForm.url"
                                label="Link al prodotto (facoltativo)"
                                placeholder="https://"
                                prepend-inner-icon="mdi-link-variant"
                                hint="Resta sull'articolo, comodo per riordinarlo"
                                persistent-hint
                                class="mb-2"
                            />
                        </template>
                    </template>
                    <v-row dense>
                        <v-col cols="8">
                            <v-combobox v-model="linkForm.category" :items="categoryNames" label="Categoria" clearable />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field v-model.number="linkForm.quantity" label="Q.tà" type="number" min="0" />
                        </v-col>
                    </v-row>
                    <template v-if="linkResult">
                        <v-divider class="my-3" />
                        <v-text-field v-model="linkResult.description" label="Articolo" class="mb-2" />
                        <v-text-field v-model.number="linkResult.unit_cost" label="Costo unitario" type="number" step="0.01" prefix="€" />
                        <div class="text-caption text-medium-emphasis">{{ linkResult.note }}</div>
                    </template>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="linkDialog = false">Annulla</v-btn>
                    <template v-if="!linkResult">
                        <v-btn
                            v-if="linkMode === 'link'"
                            color="primary"
                            variant="flat"
                            :loading="linkLoading"
                            :disabled="!linkForm.url"
                            @click="readLink"
                        >
                            Leggi la pagina
                        </v-btn>
                        <v-btn v-else color="primary" variant="flat" :loading="linkLoading" :disabled="!linkImage || !canUseAi" @click="readImage">
                            Leggi lo screenshot
                        </v-btn>
                    </template>
                    <v-btn v-else color="primary" variant="flat" prepend-icon="mdi-plus" @click="addFromLink">Aggiungi</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<style scoped>
.paste-zone {
    border: 1px dashed rgba(var(--v-border-color), 0.4);
    border-radius: 6px;
    cursor: pointer;
    max-height: 320px;
    overflow: auto;
}

.paste-zone--active,
.paste-zone:focus-visible {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.04);
    outline: none;
}

.paste-zone__image {
    display: block;
    max-width: 100%;
}

.estimate-group__header {
    background: rgba(var(--v-theme-primary), 0.04);
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.estimate-item {
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

@media (min-width: 1280px) {
    .estimate-sidebar {
        position: sticky;
        top: 80px;
    }
}
</style>
