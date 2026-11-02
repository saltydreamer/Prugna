import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';

import vuetify from './plugins/vuetify';
import AppLayout from './layouts/AppLayout.vue';
import AuthLayout from './layouts/AuthLayout.vue';
import PublicLayout from './layouts/PublicLayout.vue';

createInertiaApp({
    title: (title, page) => {
        const appName = page?.props?.app?.name ?? 'Laravel';

        return title ? `${title} · ${appName}` : appName;
    },
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue');
        const page = pages[`./pages/${name}.vue`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return page();
    },
    // Pages under "auth/" (and the error page for guests) use the centered auth layout,
    // everything else the application shell. A page can still override it with `defineOptions({ layout })`.
    layout: (name, page) => {
        if (name.startsWith('auth/')) {
            return AuthLayout;
        }

        // Pages shared with customers (no login).
        if (name.startsWith('public/')) {
            return PublicLayout;
        }

        if (name === 'Error' && !page.props.auth?.user) {
            return AuthLayout;
        }

        return AppLayout;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(vuetify)
            .mount(el);
    },
    progress: {
        color: '#7986CB',
        showSpinner: false,
    },
});
