import '@mdi/font/css/materialdesignicons.css';
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

const brand = {
    primary: '#3F51B5',
    secondary: '#5C6BC0',
    accent: '#00BFA5',
    info: '#0288D1',
    success: '#2E7D32',
    warning: '#ED6C02',
    error: '#D32F2F',
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
                    background: '#F5F6FA',
                    surface: '#FFFFFF',
                },
            },
            dark: {
                dark: true,
                colors: {
                    ...brand,
                    primary: '#7986CB',
                    secondary: '#9FA8DA',
                    background: '#121318',
                    surface: '#1C1D24',
                },
            },
        },
    },
    // Application-wide component defaults: change them here to restyle the whole UI.
    defaults: {
        VTextField: { variant: 'outlined', density: 'comfortable', color: 'primary' },
        VTextarea: { variant: 'outlined', density: 'comfortable', color: 'primary' },
        VSelect: { variant: 'outlined', density: 'comfortable', color: 'primary' },
        VAutocomplete: { variant: 'outlined', density: 'comfortable', color: 'primary' },
        VFileInput: { variant: 'outlined', density: 'comfortable', color: 'primary' },
        VCheckbox: { color: 'primary', density: 'comfortable', hideDetails: 'auto' },
        VSwitch: { color: 'primary', inset: true, hideDetails: 'auto' },
        VCard: { rounded: 'lg' },
        VBtn: { rounded: 'lg' },
        VChip: { size: 'small' },
    },
});
