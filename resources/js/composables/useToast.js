import { ref } from 'vue';

const queue = ref([]);

const colors = {
    success: 'success',
    error: 'error',
    warning: 'warning',
    info: 'info',
};

/**
 * Global toast queue rendered by <ToastQueue /> (Vuetify VSnackbarQueue).
 * Server side, use: Inertia::flash('toast', ['type' => 'success', 'message' => '...']).
 */
export function useToast() {
    const push = (message, type = 'info', options = {}) => {
        queue.value.push({
            text: message,
            color: colors[type] ?? type,
            timeout: type === 'error' ? 8000 : 4000,
            ...options,
        });
    };

    return {
        queue,
        push,
        success: (message, options) => push(message, 'success', options),
        error: (message, options) => push(message, 'error', options),
        warning: (message, options) => push(message, 'warning', options),
        info: (message, options) => push(message, 'info', options),
    };
}
