<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage, usePoll } from '@inertiajs/vue3';
import { useDisplay, useTheme } from 'vuetify';
import { route } from 'ziggy-js';

import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DocumentImportDialog from '@/components/DocumentImportDialog.vue';
import InterventionStartDialog from '@/components/interventions/InterventionStartDialog.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import ToastQueue from '@/components/ToastQueue.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { THEME_STORAGE_KEY } from '@/plugins/vuetify';
import { formatElapsed } from '@/utils/duration';
import { installApp, pwa, tellServiceWorker } from '@/utils/pwa';
import { fieldQueue, startFieldSync } from '@/utils/fieldQueue';

const page = usePage();
const theme = useTheme();
const { mobile } = useDisplay();
const { can } = usePermissions();
const { link } = useInertiaLink();

const user = computed(() => page.props.auth.user);
const appName = computed(() => page.props.app?.name ?? 'Laravel');
const unreadCount = computed(() => page.props.inbox?.unread_count ?? 0);

// Drawer: temporary on mobile, permanent (collapsible to rail) on desktop.
const drawer = ref(!mobile.value);
const rail = ref(false);

watch(mobile, (isMobile) => {
    drawer.value = !isMobile;
    rail.value = false;
});

function toggleDrawer() {
    if (mobile.value) {
        drawer.value = !drawer.value;
    } else {
        rail.value = !rail.value;
    }
}

// Keep the unread badge fresh without websockets. For real time updates see the README (Laravel Reverb).
usePoll(60_000, { only: ['inbox'] }, { keepAlive: false });

/**
 * Customer or supplier, on the organization pages (to highlight the right registry).
 */
function organizationPageRole() {
    return route().current('registry.organizations.*') ? (page.props.organization?.role ?? page.props.role?.value) : null;
}

/**
 * Navigation: add entries here. `permission` hides the item when the user lacks it,
 * `active` is a Ziggy route pattern used to highlight the current section.
 */
const navigation = computed(() => {
    // Re-evaluate on every visit so that `route().current()` is up to date.
    void page.url;

    const sections = [
        {
            items: [
                { title: 'Dashboard', icon: 'mdi-view-dashboard-outline', route: 'dashboard', active: 'dashboard' },
                { title: 'Notifiche', icon: 'mdi-bell-outline', route: 'notifications.index', active: 'notifications.*', badge: unreadCount.value },
            ],
        },
        {
            title: 'Vendite',
            items: [
                {
                    title: 'Preventivi',
                    icon: 'mdi-file-document-edit-outline',
                    route: 'sales.quotes.index',
                    active: 'sales.quotes.*',
                    permission: 'quotes.view',
                },
                {
                    title: 'Ordini',
                    icon: 'mdi-clipboard-text-outline',
                    route: 'sales.orders.index',
                    active: 'sales.orders.*',
                    permission: 'orders.view',
                },
                {
                    title: 'Fatture',
                    icon: 'mdi-receipt-text-outline',
                    route: 'sales.invoices.index',
                    active: 'sales.invoices.*',
                    permission: 'invoices.view',
                },
            ],
        },
        {
            title: 'Assistenza',
            items: [
                {
                    title: 'Interventi',
                    icon: 'mdi-timer-outline',
                    route: 'interventions.index',
                    active: () =>
                        route().current('interventions.*') &&
                        !route().current('interventions.settings.*') &&
                        !route().current('interventions.contracts.*'),
                    permission: ['interventions.view', 'interventions.supervise'],
                },
                {
                    title: 'Sul campo',
                    icon: 'mdi-cellphone-marker',
                    route: 'field.index',
                    active: 'field.*',
                    permission: 'interventions.manage',
                },
                {
                    title: 'Contratti monte ore',
                    icon: 'mdi-timer-sand-complete',
                    route: 'interventions.contracts.index',
                    active: 'interventions.contracts.*',
                    permission: ['contracts.manage', 'interventions.supervise'],
                },
                {
                    title: 'Impostazioni interventi',
                    icon: 'mdi-timer-cog-outline',
                    route: 'interventions.settings.index',
                    active: 'interventions.settings.*',
                    permission: 'settings.manage',
                },
            ],
        },
        {
            title: 'Acquisti',
            items: [
                {
                    title: 'Spese e scadenze',
                    icon: 'mdi-receipt-text-outline',
                    route: 'purchases.expenses.index',
                    active: 'purchases.expenses.*',
                    permission: 'expenses.view',
                },
                {
                    title: 'Fatture ricevute',
                    icon: 'mdi-inbox-arrow-down-outline',
                    route: 'purchases.received.index',
                    active: 'purchases.received.*',
                    permission: 'expenses.view',
                },
                {
                    title: 'Ordini fornitore',
                    icon: 'mdi-cart-outline',
                    route: 'purchases.orders.index',
                    active: 'purchases.orders.*',
                    permission: 'purchases.view',
                },
            ],
        },
        {
            title: 'Anagrafiche',
            items: [
                {
                    title: 'Clienti',
                    icon: 'mdi-domain',
                    route: 'registry.customers.index',
                    active: () => route().current('registry.customers.*') || organizationPageRole() === 'customer',
                    permission: 'organizations.view',
                },
                {
                    title: 'Fornitori',
                    icon: 'mdi-truck-outline',
                    route: 'registry.suppliers.index',
                    active: () => route().current('registry.suppliers.*') || organizationPageRole() === 'supplier',
                    permission: 'suppliers.view',
                },
                {
                    title: 'Contatti',
                    icon: 'mdi-card-account-phone-outline',
                    route: 'registry.contacts.index',
                    active: 'registry.contacts.*',
                    permission: ['organizations.view', 'suppliers.view'],
                },
            ],
        },
        {
            title: 'Catalogo',
            items: [
                {
                    title: 'Prodotti e servizi',
                    icon: 'mdi-package-variant-closed',
                    route: 'catalog.products.index',
                    active: 'catalog.products.*',
                    permission: 'products.view',
                },
                {
                    title: 'Categorie e IVA',
                    icon: 'mdi-tune-variant',
                    route: 'catalog.settings.index',
                    active: 'catalog.settings.*',
                    permission: 'catalog.settings',
                },
            ],
        },
        {
            title: 'Amministrazione',
            items: [
                {
                    title: 'Utenti',
                    icon: 'mdi-account-multiple-outline',
                    route: 'admin.users.index',
                    active: 'admin.users.*',
                    permission: 'users.view',
                },
                {
                    title: 'Ruoli e permessi',
                    icon: 'mdi-shield-key-outline',
                    route: 'admin.roles.index',
                    active: 'admin.roles.*',
                    permission: 'roles.view',
                },
                {
                    title: 'Annunci',
                    icon: 'mdi-bullhorn-outline',
                    route: 'admin.announcements.create',
                    active: 'admin.announcements.*',
                    permission: 'notifications.send',
                },
                {
                    title: 'Fatturazione elettronica',
                    icon: 'mdi-bank-transfer',
                    route: 'settings.einvoicing.index',
                    active: 'settings.einvoicing.*',
                    permission: 'settings.manage',
                },
                {
                    title: 'Impostazioni',
                    icon: 'mdi-cog-outline',
                    route: 'settings.index',
                    active: () => route().current('settings.*') && !route().current('settings.einvoicing.*'),
                    permission: 'settings.manage',
                },
            ],
        },
    ];

    return sections
        .map((section) => ({
            ...section,
            items: section.items
                .filter((item) => !item.permission || [item.permission].flat().some((permission) => can(permission)))
                .map((item) => ({ ...item, isActive: typeof item.active === 'function' ? item.active() : route().current(item.active) })),
        }))
        .filter((section) => section.items.length > 0);
});

const themeOptions = [
    { value: 'light', title: 'Chiaro', icon: 'mdi-white-balance-sunny' },
    { value: 'dark', title: 'Scuro', icon: 'mdi-weather-night' },
    { value: 'system', title: 'Sistema', icon: 'mdi-theme-light-dark' },
];

const selectedTheme = ref(theme.isSystem.value ? 'system' : theme.name.value);

function setTheme(value) {
    selectedTheme.value = value;
    theme.change(value);

    try {
        localStorage.setItem(THEME_STORAGE_KEY, value);
    } catch {
        // Storage unavailable (private mode): the choice lasts for this page only.
    }
}

const importDialog = ref(false);
const canImport = computed(() => can('expenses.manage') || (can('ai.use') && (can('organizations.create') || can('suppliers.create'))));

// --- Intervention timer -------------------------------------------------------------------
const running = computed(() => page.props.runningIntervention);
const startDialog = ref(false);
const now = ref(Date.now());
let ticker;

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);

    // Interventions recorded offline are sent as soon as there is a connection, from any page.
    if (can('interventions.manage')) {
        startFieldSync(user.value.id);
        // Keep "Interventi sul campo" ready for offline use.
        navigator.serviceWorker?.ready.then((registration) => registration.active?.postMessage('warm')).catch(() => null);
    }
});
onBeforeUnmount(() => clearInterval(ticker));

const elapsed = computed(() => (running.value ? formatElapsed(running.value.started_at, now.value) : ''));

function stopRunning() {
    router.post(route('interventions.stop', running.value.id));
}

const themeIcon = computed(() => themeOptions.find((option) => option.value === selectedTheme.value)?.icon);

function logout() {
    // Pages kept for offline use belong to this user.
    tellServiceWorker('clear');
    router.post(route('logout'));
}
</script>

<template>
    <v-app>
        <v-navigation-drawer v-model="drawer" :rail="rail" :temporary="mobile" width="236" class="app-drawer">
            <v-list-item class="py-3" :prepend-icon="rail ? 'mdi-cube-outline' : undefined" v-bind="link(route('dashboard'))">
                <template v-if="!rail" #prepend>
                    <v-avatar color="primary" rounded="md" size="28">
                        <v-icon icon="mdi-cube-outline" size="18" />
                    </v-avatar>
                </template>
                <v-list-item-title class="text-body-1 font-weight-bold">{{ appName }}</v-list-item-title>
            </v-list-item>

            <v-divider />

            <v-list nav density="compact" color="primary">
                <template v-for="(section, index) in navigation" :key="index">
                    <v-list-subheader v-if="section.title && !rail">{{ section.title }}</v-list-subheader>
                    <v-divider v-else-if="section.title" class="my-2" />

                    <v-list-item
                        v-for="item in section.items"
                        :key="item.route"
                        :prepend-icon="item.icon"
                        :title="item.title"
                        :active="item.isActive"
                        v-bind="link(route(item.route))"
                    >
                        <template v-if="item.badge && !rail" #append>
                            <v-badge :content="item.badge" color="error" inline />
                        </template>
                    </v-list-item>
                </template>
            </v-list>
        </v-navigation-drawer>

        <v-app-bar flat border="b" density="compact">
            <v-app-bar-nav-icon @click="toggleDrawer" />
            <v-spacer />

            <v-chip
                v-if="!fieldQueue.online"
                color="warning"
                variant="tonal"
                prepend-icon="mdi-cloud-off-outline"
                class="mr-1"
                v-bind="link(route('field.index'))"
            >
                Offline
            </v-chip>
            <v-btn
                v-if="fieldQueue.pending"
                variant="text"
                :title="`${fieldQueue.pending} interventi da sincronizzare`"
                aria-label="Interventi da sincronizzare"
                v-bind="link(route('field.index'))"
                icon
            >
                <v-badge :content="fieldQueue.pending" color="warning">
                    <v-icon :icon="fieldQueue.syncing ? 'mdi-cloud-sync-outline' : 'mdi-cloud-upload-outline'" />
                </v-badge>
            </v-btn>

            <template v-if="running">
                <v-chip
                    color="info"
                    variant="tonal"
                    prepend-icon="mdi-timer-outline"
                    class="mr-1 font-weight-medium"
                    :title="`${running.number} · ${running.customer}`"
                    v-bind="link(route('interventions.show', running.id))"
                >
                    <span class="d-none d-sm-inline mr-2 text-truncate" style="max-width: 180px">{{ running.customer }}</span>
                    <span style="font-variant-numeric: tabular-nums">{{ elapsed }}</span>
                </v-chip>
                <v-btn
                    icon="mdi-stop-circle-outline"
                    color="error"
                    variant="text"
                    title="Termina intervento"
                    aria-label="Termina intervento"
                    @click="stopRunning"
                />
            </template>
            <v-btn
                v-else-if="can('interventions.manage')"
                icon="mdi-timer-play-outline"
                variant="text"
                title="Avvia intervento"
                aria-label="Avvia intervento"
                @click="startDialog = true"
            />

            <v-menu location="bottom end">
                <template #activator="{ props: activator }">
                    <v-btn v-bind="activator" :icon="themeIcon" variant="text" aria-label="Tema" />
                </template>
                <v-list density="compact" :selected="[selectedTheme]" color="primary">
                    <v-list-item
                        v-for="option in themeOptions"
                        :key="option.value"
                        :value="option.value"
                        :prepend-icon="option.icon"
                        :title="option.title"
                        @click="setTheme(option.value)"
                    />
                </v-list>
            </v-menu>

            <v-btn
                v-if="canImport"
                icon="mdi-file-upload-outline"
                variant="text"
                title="Carica documento"
                aria-label="Carica documento"
                @click="importDialog = true"
            />

            <NotificationBell />

            <v-menu location="bottom end" min-width="240">
                <template #activator="{ props: activator }">
                    <v-btn v-bind="activator" icon class="mr-2" aria-label="Menu utente">
                        <UserAvatar :user="user" size="36" />
                    </v-btn>
                </template>
                <v-card>
                    <v-list>
                        <v-list-item :title="user.name" :subtitle="user.email">
                            <template #prepend>
                                <UserAvatar :user="user" class="mr-3" />
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-divider />
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-account-circle-outline" title="Profilo" v-bind="link(route('profile.show'))" />
                        <v-list-item
                            prepend-icon="mdi-shield-lock-outline"
                            title="Sicurezza"
                            v-bind="link(route('profile.show', { tab: 'security' }))"
                        />
                        <v-list-item prepend-icon="mdi-bell-outline" title="Notifiche" v-bind="link(route('notifications.index'))" />
                        <v-list-item v-if="pwa.installable" prepend-icon="mdi-cellphone-arrow-down" title="Installa l'app" @click="installApp" />
                        <v-list-item
                            v-if="can('settings.manage')"
                            prepend-icon="mdi-cog-outline"
                            title="Impostazioni"
                            v-bind="link(route('settings.index'))"
                        />
                        <v-divider class="my-1" />
                        <v-list-item prepend-icon="mdi-logout" title="Esci" base-color="error" @click="logout" />
                    </v-list>
                </v-card>
            </v-menu>
        </v-app-bar>

        <v-main>
            <v-container class="py-4 py-md-6 px-4 px-md-6" style="max-width: 1320px">
                <slot />
            </v-container>
        </v-main>

        <ToastQueue />
        <ConfirmDialog />
        <DocumentImportDialog v-if="canImport" v-model="importDialog" />
        <InterventionStartDialog v-if="can('interventions.manage')" v-model="startDialog" />
    </v-app>
</template>

<style scoped>
.app-drawer :deep(.v-list-item--nav) {
    min-height: 34px;
    margin-bottom: 1px;
}

.app-drawer :deep(.v-list-item--nav .v-list-item__prepend > .v-icon) {
    font-size: 19px;
    opacity: 0.75;
}

.app-drawer :deep(.v-list-item--nav .v-list-item__spacer) {
    width: 14px;
}

.app-drawer :deep(.v-list-item--active .v-list-item__prepend > .v-icon) {
    opacity: 1;
}
</style>
