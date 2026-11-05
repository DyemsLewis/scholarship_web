import { ref } from 'vue';
import { concernError } from '../support/agreementConcerns.js';

export function useConcernMutation(send) {
    const busy = ref(false), stale = ref(false), error = ref('');
    let version = 0;
    async function run(method, url, body) {
        if (busy.value || stale.value || !url) return null;
        const token = ++version;
        busy.value = true; error.value = '';
        try {
            const { data } = await send(method, url, body, { portalToast: false });
            return token === version ? data : null;
        } catch (failure) {
            if (token === version) {
                stale.value = [403, 404, 409].includes(failure.response?.status);
                error.value = stale.value ? 'This record or your access changed. Reload before continuing. Your unsaved text has been kept.' : concernError(failure);
            }
            return null;
        } finally { if (token === version) busy.value = false; }
    }
    function reset() { version++; busy.value = false; stale.value = false; error.value = ''; }
    return { busy, stale, error, run, reset };
}
