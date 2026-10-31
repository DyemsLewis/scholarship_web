import { getCurrentScope, onScopeDispose, ref, shallowRef } from 'vue';

export function useAdminResource(fetchResource, { initialData = null, failureMessage = 'This record could not be loaded.' } = {}) {
    const data = shallowRef(initialData);
    const isLoading = ref(true);
    const hasLoaded = ref(false);
    const errorMessage = ref('');
    let version = 0;
    let controller = null;
    let disposed = false;

    // Invalidate immediately when filters change, before a debounced replacement starts.
    function invalidate() {
        version++;
        controller?.abort();
        controller = null;
        if (!disposed) {
            isLoading.value = true;
            errorMessage.value = '';
        }
    }

    async function load(...args) {
        if (disposed) return null;
        invalidate();
        const requestVersion = version;
        const requestController = new AbortController();
        controller = requestController;

        try {
            const payload = await fetchResource({ signal: requestController.signal }, ...args);
            if (disposed || requestVersion !== version) return null;
            data.value = payload;
            hasLoaded.value = true;
            return payload;
        } catch (error) {
            if (disposed || requestVersion !== version) return null;
            errorMessage.value = error?.response?.data?.message || failureMessage;
            return null;
        } finally {
            if (!disposed && requestVersion === version) {
                isLoading.value = false;
                controller = null;
            }
        }
    }

    function dispose() {
        disposed = true;
        invalidate();
        isLoading.value = false;
    }

    if (getCurrentScope()) onScopeDispose(dispose);

    return { data, isLoading, hasLoaded, errorMessage, load, invalidate, dispose };
}
