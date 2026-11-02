<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage, usePoll } from '@inertiajs/vue3';
import { useDisplay, useTheme } from 'vuetify';
import { route } from 'ziggy-js';

import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DocumentImportDialog from '@/components/DocumentImportDialog.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import ToastQueue from '@/components/ToastQueue.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { useInertiaLink } from '@/composables/useInertiaLink';
import { usePermissions } from '@/composables/usePermissions';
import { THEME_STORAGE_KEY } from '@/plugins/vuetify';

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
                    title: 'Impostazioni',
                    icon: 'mdi-cog-outline',
                    route: 'settings.index',
                    active: 'settings.*',
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

const themeIcon = computed(() => themeOptions.find((option) => option.value === selectedTheme.value)?.icon);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <v-app>
        <v-navigation-drawer v-model="drawer" :rail="rail" :temporary="mobile" width="264">
            <v-list-item class="py-4" :prepend-icon="rail ? 'mdi-cube-outline' : undefined" v-bind="link(route('dashboard'))">
                <template v-if="!rail" #prepend>
                    <v-avatar color="primary" rounded="lg" size="36">
                        <v-icon icon="mdi-cube-outline" />
                    </v-avatar>
                </template>
                <v-list-item-title class="text-subtitle-1 font-weight-bold">{{ appName }}</v-list-item-title>
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

        <v-app-bar flat border="b">
            <v-app-bar-nav-icon @click="toggleDrawer" />
            <v-spacer />

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
            <v-container class="py-6 py-md-8" style="max-width: 1280px">
                <slot />
            </v-container>
        </v-main>

        <ToastQueue />
        <ConfirmDialog />
        <DocumentImportDialog v-if="canImport" v-model="importDialog" />
    </v-app>
</template>
