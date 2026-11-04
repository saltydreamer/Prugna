import '@mdi/font/css/materialdesignicons.css';
import '@fontsource-variable/inter';
import 'vuetify/styles';

import { createVuetify } from 'vuetify';
import { it, en } from 'vuetify/locale';
import { aliases, mdi } from 'vuetify/iconsets/mdi';

export const THEME_STORAGE_KEY = 'theme';

function storedTheme() {
    try {
        return localStorage.getItem(THEME_STORAGE_KEY) ?? 'system';
    } catch {
        return 'system';
    }
}

// Restrained palette: one accent color, neutral greys, muted status colors.
const brand = {
    primary: '#4F46E5',
    secondary: '#64748B',
    accent: '#0EA5E9',
    info: '#0284C7',
    success: '#16A34A',
    warning: '#D97706',
    error: '#DC2626',
};

export default createVuetify({
    locale: {
        locale: 'it',
        fallback: 'en',
        messages: { it, en },
    },
    icons: {
        defaultSet: 'mdi',
        aliases,
        sets: { mdi },
    },
    theme: {
        defaultTheme: storedTheme(),
        themes: {
            light: {
                dark: false,
                colors: {
                    ...brand,
                    background: '#F8FAFC',
                    surface: '#FFFFFF',
                    'surface-variant': '#F1F5F9',
                    'on-surface-variant': '#334155',
                },
                variables: {
                    'border-color': '#0F172A',
                    'border-opacity': 0.09,
                    'medium-emphasis-opacity': 0.62,
                },
            },
            dark: {
                dark: true,
                colors: {
                    ...brand,
                    primary: '#818CF8',
                    secondary: '#94A3B8',
                    background: '#0B0D12',
                    surface: '#13161D',
                    'surface-variant': '#1E222B',
                    'on-surface-variant': '#CBD5E1',
                },
                variables: {
                    'border-color': '#FFFFFF',
                    'border-opacity': 0.1,
                },
            },
        },
    },
    // Application-wide component defaults: change them here to restyle the whole UI.
    defaults: {
        global: { ripple: false },
        VTextField: { variant: 'outlined', density: 'compact', color: 'primary' },
        VTextarea: { variant: 'outlined', density: 'compact', color: 'primary' },
        VSelect: { variant: 'outlined', density: 'compact', color: 'primary' },
        VAutocomplete: { variant: 'outlined', density: 'compact', color: 'primary' },
        VCombobox: { variant: 'outlined', density: 'compact', color: 'primary' },
        VFileInput: { variant: 'outlined', density: 'compact', color: 'primary' },
        VCheckbox: { color: 'primary', density: 'compact', hideDetails: 'auto' },
        VSwitch: { color: 'primary', inset: true, density: 'compact', hideDetails: 'auto' },
        // Flat cards with a hairline border instead of shadows.
        VCard: { rounded: 'lg', elevation: 0, border: true },
        VBtn: { rounded: 'md', density: 'comfortable', variant: 'flat' },
        VBtnToggle: { density: 'comfortable' },
        VChip: { size: 'small', rounded: 'md' },
        VTabs: { density: 'compact' },
        VTab: { slim: true },
        VList: { density: 'compact' },
        VTable: { density: 'compact' },
        VDataTable: { density: 'compact' },
        VDataTableServer: { density: 'compact' },
        VAlert: { density: 'compact', rounded: 'lg' },
        VDialog: { scrim: 'rgba(15, 23, 42, 0.45)' },
        VMenu: { offset: 4 },
        VTooltip: { location: 'top' },
        VEmptyState: { size: 40 },
    },
});
