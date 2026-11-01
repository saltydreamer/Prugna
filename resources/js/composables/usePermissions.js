import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Permission helpers backed by the `auth` shared props (see HandleInertiaRequests).
 * They only drive the UI: authorization is always enforced on the server.
 */
export function usePermissions() {
    const page = usePage();

    const permissions = computed(() => page.props.auth?.permissions ?? []);
    const roles = computed(() => page.props.auth?.roles ?? []);

    /** @param {string|string[]} permission  any of the given permissions */
    const can = (permission) => {
        const list = Array.isArray(permission) ? permission : [permission];

        return list.some((item) => permissions.value.includes(item));
    };

    /** @param {string|string[]} role  any of the given roles */
    const hasRole = (role) => {
        const list = Array.isArray(role) ? role : [role];

        return list.some((item) => roles.value.includes(item));
    };

    return { permissions, roles, can, hasRole };
}
