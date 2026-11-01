import { router } from '@inertiajs/vue3';

/**
 * Turns any Vuetify component accepting `href` (v-btn, v-list-item, v-card...)
 * into an Inertia link, keeping native behaviour for ctrl/cmd/middle clicks.
 *
 *   <v-list-item v-bind="link(route('dashboard'))" />
 */
export function useInertiaLink() {
    const link = (href, options = {}) => ({
        href,
        onClick: (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            router.visit(href, options);
        },
    });

    return { link };
}
