import { reactive } from 'vue';

export function useConfirmationDialog() {
    const confirmation = reactive({
        open: false,
        title: '',
        message: '',
        confirmLabel: 'Confirm',
        tone: 'warning',
    });
    let resolver = null;

    function requestConfirmation(options = {}) {
        if (resolver) {
            resolver(false);
        }

        Object.assign(confirmation, {
            open: true,
            title: options.title ?? 'Confirm action',
            message: options.message ?? 'Please confirm that you want to continue.',
            confirmLabel: options.confirmLabel ?? 'Confirm',
            tone: options.tone ?? 'warning',
        });

        return new Promise((resolve) => {
            resolver = resolve;
        });
    }

    function resolveConfirmation(confirmed) {
        confirmation.open = false;
        const currentResolver = resolver;
        resolver = null;
        currentResolver?.(confirmed);
    }

    return {
        confirmation,
        requestConfirmation,
        confirmConfirmation: () => resolveConfirmation(true),
        cancelConfirmation: () => resolveConfirmation(false),
    };
}
