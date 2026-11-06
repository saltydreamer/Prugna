<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import PageHeader from '@/components/PageHeader.vue';
import CustomerPriceList from '@/components/catalog/CustomerPriceList.vue';
import CompanyDataDialog from '@/components/registry/CompanyDataDialog.vue';
import ContactDialog from '@/components/registry/ContactDialog.vue';
import LocationDialog from '@/components/registry/LocationDialog.vue';
import InterventionInvoiceDialog from '@/components/interventions/InterventionInvoiceDialog.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { formatHours } from '@/utils/duration';

const props = defineProps({
    organization: { type: Object, required: true },
    /** { value, label, plural, index_route, create_route } */
    role: { type: Object, required: true },
    /** Suppliers: latest supplier orders (null without "purchases.view"). */
    purchaseOrders: { type: Array, default: null },
    locationTypes: { type: Array, required: true },
    /** null when the user can't see the catalog. */
    customerPrices: { type: Array, default: null },
    productOptions: { type: Array, default: null },
    /** Customers: interventions counters (null without access). */
    interventions: { type: Object, default: null },
    /** Customers: active hour contracts (null without access). */
    contracts: { type: Array, default: null },
    /** «Aggiorna dal Registro Imprese» available (Openapi "Company" service switched on). */
    companyLookup: { type: Boolean, default: false },
});

const companyDialog = ref(false);

const interventionDialog = ref(false);

const { link } = useInertiaLink();
const { confirm } = useConfirm();
const { can } = usePermissions();

const typeIcons = {
    company: 'mdi-domain',
    freelancer: 'mdi-briefcase-account-outline',
    public_body: 'mdi-bank-outline',
    non_profit: 'mdi-hand-heart-outline',
    other: 'mdi-shape-outline',
};

const tabs = ['overview', 'locations', 'contacts', 'prices', 'purchases'];
const initialTab = new URLSearchParams(window.location.search).get('tab');
const tab = ref(tabs.includes(initialTab) ? initialTab : 'overview');

watch(tab, (value) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
});

const canEdit = computed(() => props.organization.can?.update);
const archived = computed(() => !!props.organization.archived_at);
const primaryLocation = computed(() => props.organization.locations.find((location) => location.is_primary));
const primaryContact = computed(() => props.organization.contacts.find((contact) => contact.is_primary));

const fiscal = computed(() => [
    { label: 'Partita IVA', value: props.organization.vat_number },
    { label: 'Codice fiscale', value: props.organization.tax_code },
    { label: 'Codice SDI', value: props.organization.sdi_code },
    { label: 'Condizione di pagamento', value: props.organization.payment_term?.name },
]);

const contactsInfo = computed(() => [
    {
        label: 'Email',
        value: props.organization.email,
        href: props.organization.email && `mailto:${props.organization.email}`,
        icon: 'mdi-email-outline',
    },
    {
        label: 'PEC',
        value: props.organization.pec,
        href: props.organization.pec && `mailto:${props.organization.pec}`,
        icon: 'mdi-email-lock-outline',
    },
    {
        label: 'Telefono',
        value: props.organization.phone,
        href: props.organization.phone && `tel:${props.organization.phone}`,
        icon: 'mdi-phone-outline',
    },
    { label: 'Sito web', value: props.organization.website, href: props.organization.website, icon: 'mdi-web', external: true },
]);

// --- Locations ---------------------------------------------------------------
const locationDialog = ref(false);
const editingLocation = ref(null);

function openLocation(location = null) {
    editingLocation.value = location;
    locationDialog.value = true;
}

async function deleteLocation(location) {
    const confirmed = await confirm({
        title: `Eliminare la sede "${location.name}"?`,
        message: location.contacts_count ? `${location.contacts_count} contatti collegati resteranno nell'organizzazione, senza sede.` : '',
        confirmText: 'Elimina',
        color: 'error',
    });

    if (confirmed) {
        router.delete(route('registry.organizations.locations.destroy', [props.organization.id, location.id]), { preserveScroll: true });
    }
}

function makePrimaryLocation(location) {
    router.put(
        route('registry.organizations.locations.update', [props.organization.id, location.id]),
        { ...location, is_primary: true },
        { preserveScroll: true },
    );
}

function mapsUrl(location) {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(location.full_address)}`;
}

// --- Contacts ----------------------------------------------------------------
const contactDialog = ref(false);
const editingContact = ref(null);

function openContact(contact = null) {
    editingContact.value = contact;
    contactDialog.value = true;
}

async function deleteContact(contact) {
    if (await confirm({ title: `Eliminare ${contact.full_name}?`, confirmText: 'Elimina', color: 'error' })) {
        router.delete(route('registry.organizations.contacts.destroy', [props.organization.id, contact.id]), { preserveScroll: true });
    }
}

function makePrimaryContact(contact) {
    router.put(
        route('registry.organizations.contacts.update', [props.organization.id, contact.id]),
        { ...contact, is_primary: true },
        { preserveScroll: true },
    );
}

const contactHeaders = [
    { title: 'Nome', key: 'full_name' },
    { title: 'Ruolo', key: 'job_title' },
    { title: 'Email', key: 'email' },
    { title: 'Telefono', key: 'phone' },
    { title: 'Sede', key: 'location' },
    { title: '', key: 'actions', align: 'end', sortable: false },
];

// --- Organization --------------------------------------------------------------
async function archive() {
    const confirmed = await confirm({
        title: `Archiviare ${props.organization.name}?`,
        message: "Non comparirà più negli elenchi, ma i dati restano disponibili e potrai ripristinarla dalla scheda 'Archiviate'.",
        confirmText: 'Archivia',
        color: 'warning',
        icon: 'mdi-archive-outline',
    });

    if (confirmed) {
        router.delete(route('registry.organizations.destroy', props.organization.id));
    }
}

function restore() {
    router.patch(route('registry.organizations.restore', props.organization.id));
}
</script>

<template>
    <div>
        <PageHeader
            :title="organization.name"
            :subtitle="[role.label, organization.vat_number ? `P.IVA ${organization.vat_number}` : organization.type_label].join(' · ')"
        >
            <template #actions>
                <v-btn variant="text" prepend-icon="mdi-arrow-left" v-bind="link(route(role.index_route, archived ? { status: 'archived' } : {}))">
                    {{ role.plural }}
                </v-btn>
                <v-btn
                    v-if="role.value === 'customer' && can('quotes.create') && !archived"
                    variant="tonal"
                    prepend-icon="mdi-file-document-plus-outline"
                    v-bind="link(route('sales.quotes.create', { organization: organization.id }))"
                >
                    Nuovo preventivo
                </v-btn>
                <v-btn
                    v-if="role.value === 'supplier' && can('purchases.manage') && !archived"
                    variant="tonal"
                    prepend-icon="mdi-cart-plus"
                    v-bind="link(route('purchases.orders.create', { supplier: organization.id }))"
                >
                    Nuovo ordine fornitore
                </v-btn>
                <v-btn
                    v-if="companyLookup && canEdit && !archived && (organization.vat_number || organization.tax_code)"
                    variant="text"
                    prepend-icon="mdi-cloud-sync-outline"
                    @click="companyDialog = true"
                >
                    Aggiorna dal Registro Imprese
                </v-btn>
                <v-btn
                    v-if="canEdit"
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-pencil-outline"
                    v-bind="link(route('registry.organizations.edit', organization.id))"
                >
                    Modifica
                </v-btn>
                <v-btn v-if="organization.can?.delete" color="warning" variant="tonal" prepend-icon="mdi-archive-outline" @click="archive">
                    Archivia
                </v-btn>
                <v-btn v-if="organization.can?.restore" color="success" variant="flat" prepend-icon="mdi-archive-arrow-up-outline" @click="restore">
                    Ripristina
                </v-btn>
            </template>
        </PageHeader>

        <v-alert v-if="archived" type="warning" variant="tonal" class="mb-6" icon="mdi-archive-outline">
            Organizzazione archiviata il {{ formatDate(organization.archived_at) }}: i dati sono in sola lettura finché non la ripristini.
        </v-alert>

        <v-card class="mb-6">
            <v-tabs v-model="tab" color="primary" show-arrows>
                <v-tab value="overview" prepend-icon="mdi-card-account-details-outline">Panoramica</v-tab>
                <v-tab value="locations" prepend-icon="mdi-map-marker-outline">
                    Sedi
                    <v-badge :content="organization.locations.length" color="secondary" inline />
                </v-tab>
                <v-tab value="contacts" prepend-icon="mdi-account-multiple-outline">
                    Contatti
                    <v-badge :content="organization.contacts.length" color="secondary" inline />
                </v-tab>
                <v-tab v-if="customerPrices !== null" value="prices" prepend-icon="mdi-tag-outline">
                    Prezzi concordati
                    <v-badge v-if="customerPrices.length" :content="customerPrices.length" color="secondary" inline />
                </v-tab>
                <v-tab v-if="purchaseOrders !== null" value="purchases" prepend-icon="mdi-cart-outline">Ordini fornitore</v-tab>
            </v-tabs>
        </v-card>

        <v-tabs-window v-model="tab">
            <!-- Overview -->
            <v-tabs-window-item value="overview">
                <v-row>
                    <v-col cols="12" md="6">
                        <v-card class="h-100">
                            <v-card-item>
                                <template #prepend>
                                    <v-avatar color="primary" variant="tonal"><v-icon :icon="typeIcons[organization.type]" /></v-avatar>
                                </template>
                                <v-card-title>Dati fiscali</v-card-title>
                                <v-card-subtitle>{{ organization.type_label }}</v-card-subtitle>
                            </v-card-item>
                            <v-list density="compact">
                                <v-list-item v-for="item in fiscal" :key="item.label" :title="item.value || '—'" :subtitle="item.label" />
                            </v-list>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-card class="h-100" title="Recapiti">
                            <v-list density="compact">
                                <v-list-item
                                    v-for="item in contactsInfo"
                                    :key="item.label"
                                    :prepend-icon="item.icon"
                                    :subtitle="item.label"
                                    :href="item.href || undefined"
                                    :target="item.external ? '_blank' : undefined"
                                    :rel="item.external ? 'noopener' : undefined"
                                >
                                    <v-list-item-title :class="{ 'text-primary': item.href }">{{ item.value || '—' }}</v-list-item-title>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-card class="h-100" title="Sede principale">
                            <v-card-text v-if="primaryLocation">
                                <div class="font-weight-medium">{{ primaryLocation.name }}</div>
                                <div>{{ primaryLocation.full_address }}</div>
                                <v-btn
                                    class="mt-2 px-0"
                                    variant="text"
                                    color="primary"
                                    size="small"
                                    :href="mapsUrl(primaryLocation)"
                                    target="_blank"
                                    rel="noopener"
                                    prepend-icon="mdi-map-outline"
                                >
                                    Apri in Google Maps
                                </v-btn>
                            </v-card-text>
                            <v-card-text v-else class="text-medium-emphasis">
                                Nessuna sede.
                                <a v-if="canEdit" href="#" @click.prevent="tab = 'locations'">Aggiungine una</a>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-card class="h-100" title="Referente principale">
                            <v-card-text v-if="primaryContact">
                                <div class="font-weight-medium">{{ primaryContact.full_name }}</div>
                                <div v-if="primaryContact.job_title" class="text-medium-emphasis">{{ primaryContact.job_title }}</div>
                                <div v-if="primaryContact.email" class="mt-1">
                                    <a :href="`mailto:${primaryContact.email}`">{{ primaryContact.email }}</a>
                                </div>
                                <div v-if="primaryContact.phone || primaryContact.mobile">{{ primaryContact.phone || primaryContact.mobile }}</div>
                            </v-card-text>
                            <v-card-text v-else class="text-medium-emphasis">
                                Nessun contatto.
                                <a v-if="canEdit" href="#" @click.prevent="tab = 'contacts'">Aggiungine uno</a>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col v-if="interventions" cols="12">
                        <v-card title="Interventi" prepend-icon="mdi-timer-outline">
                            <template #append>
                                <div class="d-flex flex-wrap ga-2">
                                    <v-btn
                                        v-if="interventions.count"
                                        variant="text"
                                        size="small"
                                        v-bind="link(route('interventions.index', { customer: organization.id }))"
                                    >
                                        Vedi tutti
                                    </v-btn>
                                    <v-btn
                                        v-if="can('contracts.manage') && !archived"
                                        variant="text"
                                        size="small"
                                        prepend-icon="mdi-timer-sand-complete"
                                        v-bind="link(route('interventions.contracts.create', { organization: organization.id }))"
                                    >
                                        Monte ore
                                    </v-btn>
                                    <v-btn
                                        v-if="can('interventions.manage') && !archived"
                                        variant="tonal"
                                        size="small"
                                        prepend-icon="mdi-plus"
                                        v-bind="link(route('interventions.create', { organization: organization.id }))"
                                    >
                                        Registra
                                    </v-btn>
                                    <v-btn
                                        v-if="can('invoices.manage') && interventions.to_invoice_count"
                                        color="primary"
                                        variant="flat"
                                        size="small"
                                        prepend-icon="mdi-receipt-text-plus-outline"
                                        @click="interventionDialog = true"
                                    >
                                        Fattura interventi
                                    </v-btn>
                                </div>
                            </template>
                            <v-card-text>
                                <div v-for="contract in contracts ?? []" :key="contract.id" class="mb-3">
                                    <div class="d-flex justify-space-between text-body-2">
                                        <a v-bind="link(route('interventions.contracts.show', contract.id))">
                                            {{ contract.number }} · {{ contract.title }}
                                        </a>
                                        <span>
                                            residuo
                                            <strong>{{ formatHours(contract.remaining_minutes) }}</strong>
                                            di {{ formatHours(contract.total_minutes) }}
                                        </span>
                                    </div>
                                    <v-progress-linear
                                        :model-value="contract.total_minutes ? (contract.used_minutes / contract.total_minutes) * 100 : 0"
                                        :color="
                                            contract.remaining_minutes <= 0
                                                ? 'error'
                                                : contract.used_minutes >= contract.total_minutes * 0.8
                                                  ? 'warning'
                                                  : 'primary'
                                        "
                                        rounded
                                        height="6"
                                    />
                                </div>
                                <span v-if="!interventions.count" class="text-medium-emphasis">Nessun intervento registrato.</span>
                                <span v-else>
                                    {{ interventions.count }} interventi registrati ·
                                    <strong>{{ interventions.to_invoice_count }} da fatturare</strong>
                                    <template v-if="interventions.to_invoice_count">({{ formatHours(interventions.to_invoice_minutes) }})</template>
                                    <template v-if="interventions.running">· {{ interventions.running }} in corso</template>
                                </span>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col v-if="organization.notes" cols="12">
                        <v-card title="Note">
                            <v-card-text class="text-pre-line">{{ organization.notes }}</v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12">
                        <div class="text-caption text-medium-emphasis">
                            Creata il {{ formatDateTime(organization.created_at) }}
                            <template v-if="organization.creator">da {{ organization.creator.name }}</template>
                            · ultima modifica {{ formatDateTime(organization.updated_at) }}
                        </div>
                    </v-col>
                </v-row>
            </v-tabs-window-item>

            <!-- Locations -->
            <v-tabs-window-item value="locations">
                <div v-if="canEdit" class="d-flex justify-end mb-4">
                    <v-btn color="primary" prepend-icon="mdi-map-marker-plus-outline" @click="openLocation()">Aggiungi sede</v-btn>
                </div>

                <v-row v-if="organization.locations.length">
                    <v-col v-for="location in organization.locations" :key="location.id" cols="12" md="6">
                        <v-card class="h-100 d-flex flex-column">
                            <v-card-item>
                                <template #prepend>
                                    <v-avatar :color="location.is_primary ? 'primary' : 'secondary'" variant="tonal">
                                        <v-icon :icon="location.is_primary ? 'mdi-star-outline' : 'mdi-map-marker-outline'" />
                                    </v-avatar>
                                </template>
                                <v-card-title>{{ location.name }}</v-card-title>
                                <v-card-subtitle>{{ location.type_label }}</v-card-subtitle>
                                <template #append>
                                    <v-chip v-if="location.is_primary" color="primary" variant="tonal">Principale</v-chip>
                                </template>
                            </v-card-item>
                            <v-card-text class="flex-grow-1">
                                <div>{{ location.full_address }}</div>
                                <div v-if="location.phone" class="mt-1">
                                    <v-icon icon="mdi-phone-outline" size="small" />
                                    {{ location.phone }}
                                </div>
                                <div v-if="location.email">
                                    <v-icon icon="mdi-email-outline" size="small" />
                                    {{ location.email }}
                                </div>
                                <div v-if="location.notes" class="text-medium-emphasis text-pre-line mt-2">{{ location.notes }}</div>
                            </v-card-text>
                            <v-card-actions>
                                <v-btn
                                    variant="text"
                                    size="small"
                                    :href="mapsUrl(location)"
                                    target="_blank"
                                    rel="noopener"
                                    prepend-icon="mdi-map-outline"
                                >
                                    Mappa
                                </v-btn>
                                <v-spacer />
                                <template v-if="canEdit">
                                    <v-btn v-if="!location.is_primary" variant="text" size="small" @click="makePrimaryLocation(location)">
                                        Rendi principale
                                    </v-btn>
                                    <v-btn icon="mdi-pencil-outline" variant="text" size="small" title="Modifica" @click="openLocation(location)" />
                                    <v-btn
                                        icon="mdi-delete-outline"
                                        variant="text"
                                        size="small"
                                        color="error"
                                        title="Elimina"
                                        @click="deleteLocation(location)"
                                    />
                                </template>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
                <v-card v-else>
                    <v-empty-state
                        icon="mdi-map-marker-off-outline"
                        title="Nessuna sede"
                        text="Aggiungi la sede legale e le sedi operative."
                        size="56"
                    >
                        <template v-if="canEdit" #actions>
                            <v-btn color="primary" @click="openLocation()">Aggiungi sede</v-btn>
                        </template>
                    </v-empty-state>
                </v-card>
            </v-tabs-window-item>

            <!-- Contacts -->
            <v-tabs-window-item value="contacts">
                <div v-if="canEdit" class="d-flex justify-end mb-4">
                    <v-btn color="primary" prepend-icon="mdi-account-plus-outline" @click="openContact()">Aggiungi contatto</v-btn>
                </div>

                <v-card>
                    <v-data-table :headers="contactHeaders" :items="organization.contacts" :items-per-page="-1" hide-default-footer hover>
                        <template #[`item.full_name`]="{ item }">
                            <div class="d-flex align-center ga-2 py-2">
                                <v-icon v-if="item.is_primary" icon="mdi-star" color="primary" size="small" title="Referente principale" />
                                <span class="font-weight-medium">{{ item.full_name }}</span>
                            </div>
                        </template>
                        <template #[`item.job_title`]="{ item }">{{ item.job_title || '—' }}</template>
                        <template #[`item.email`]="{ item }">
                            <a v-if="item.email" :href="`mailto:${item.email}`">{{ item.email }}</a>
                            <span v-else>—</span>
                        </template>
                        <template #[`item.phone`]="{ item }">
                            <div v-if="item.phone">
                                <a :href="`tel:${item.phone}`">{{ item.phone }}</a>
                            </div>
                            <div v-if="item.mobile">
                                <v-icon icon="mdi-cellphone" size="x-small" />
                                <a :href="`tel:${item.mobile}`">{{ item.mobile }}</a>
                            </div>
                            <span v-if="!item.phone && !item.mobile">—</span>
                        </template>
                        <template #[`item.location`]="{ item }">{{ item.location?.name ?? '—' }}</template>
                        <template #[`item.actions`]="{ item }">
                            <div v-if="canEdit" class="d-flex justify-end">
                                <v-btn
                                    v-if="!item.is_primary"
                                    icon="mdi-star-outline"
                                    variant="text"
                                    size="small"
                                    title="Rendi referente principale"
                                    @click="makePrimaryContact(item)"
                                />
                                <v-btn icon="mdi-pencil-outline" variant="text" size="small" title="Modifica" @click="openContact(item)" />
                                <v-btn
                                    icon="mdi-delete-outline"
                                    variant="text"
                                    size="small"
                                    color="error"
                                    title="Elimina"
                                    @click="deleteContact(item)"
                                />
                            </div>
                        </template>
                        <template #no-data>
                            <v-empty-state
                                icon="mdi-account-off-outline"
                                title="Nessun contatto"
                                text="Aggiungi le persone di riferimento dell'organizzazione."
                                size="56"
                            />
                        </template>
                    </v-data-table>
                </v-card>
            </v-tabs-window-item>

            <!-- Agreed prices -->
            <v-tabs-window-item v-if="customerPrices !== null" value="prices">
                <CustomerPriceList
                    :prices="customerPrices"
                    context="organization"
                    :organization-id="organization.id"
                    :options="productOptions"
                    options-prop="productOptions"
                    :can-edit="can('products.update') && !archived"
                />
            </v-tabs-window-item>

            <!-- Supplier orders -->
            <v-tabs-window-item v-if="purchaseOrders !== null" value="purchases">
                <v-card>
                    <v-list v-if="purchaseOrders.length">
                        <v-list-item
                            v-for="purchaseOrder in purchaseOrders"
                            :key="purchaseOrder.id"
                            :title="[purchaseOrder.number, purchaseOrder.title].filter(Boolean).join(' · ')"
                            :subtitle="`${formatDate(purchaseOrder.order_date)} · ${formatMoney(purchaseOrder.subtotal)} + IVA`"
                            prepend-icon="mdi-cart-outline"
                            v-bind="link(route('purchases.orders.show', purchaseOrder.id))"
                        >
                            <template #append>
                                <v-chip :color="purchaseOrder.status_color" size="small" variant="tonal">{{ purchaseOrder.status_label }}</v-chip>
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-empty-state v-else icon="mdi-cart-outline" title="Nessun ordine a questo fornitore" size="48" />
                </v-card>
            </v-tabs-window-item>
        </v-tabs-window>

        <InterventionInvoiceDialog v-if="interventions" v-model="interventionDialog" :organization-id="organization.id" />

        <CompanyDataDialog v-if="companyLookup" v-model="companyDialog" :organization-id="organization.id" />
        <LocationDialog v-model="locationDialog" :organization-id="organization.id" :location="editingLocation" :types="locationTypes" />
        <ContactDialog v-model="contactDialog" :organization-id="organization.id" :contact="editingContact" :locations="organization.locations" />
    </div>
</template>
