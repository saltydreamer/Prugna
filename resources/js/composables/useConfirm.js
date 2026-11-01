import { reactive } from 'vue';

const state = reactive({
    open: false,
    title: '',
    message: '',
    confirmText: 'Conferma',
    cancelText: 'Annulla',
    color: 'primary',
    icon: null,
    resolve: null,
});

/**
 * Promise based confirmation dialog, rendered once by <ConfirmDialog /> in the layouts.
 *
 *   const { confirm } = useConfirm();
 *   if (await confirm({ title: 'Eliminare?', color: 'error' })) { ... }
 */
export function useConfirm() {
    const confirm = (options = {}) =>
        new Promise((resolve) => {
            state.resolve?.(false);

            Object.assign(state, {
                title: 'Sei sicuro?',
                message: '',
                confirmText: 'Conferma',
                cancelText: 'Annulla',
                color: 'primary',
                icon: null,
                ...options,
                open: true,
                resolve,
            });
        });

    const settle = (value) => {
        state.resolve?.(value);
        state.resolve = null;
        state.open = false;
    };

    return { state, confirm, settle };
}
