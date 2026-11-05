import { ref } from 'vue';
import { withdrawalError } from '../support/recipientWithdrawal.js';

export function useWithdrawalList({ endpoint, params = () => ({}), fetcher }) {
    const rows = ref([]);
    const programs = ref([]);
    const canRequest = ref(false);
    const loading = ref(false);
    const error = ref('');
    const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
    let version = 0;
    async function load(page = 1) {
        const requestVersion = ++version;
        loading.value = true; error.value = ''; canRequest.value = false;
        try {
            const response = await fetcher(endpoint(), { params: { ...params(), page }, portalToast: false });
            if (requestVersion !== version) return false;
            const data = response.data;
            rows.value = data.requests?.data ?? [];
            programs.value = data.programs ?? [];
            canRequest.value = data.can_request === true;
            const { current_page = 1, last_page = 1, total = 0, from = null, to = null } = data.requests ?? {};
            pagination.value = { current_page, last_page, total, from, to };
            return true;
        } catch (failure) {
            if (requestVersion === version) {
                rows.value = [];
                error.value = withdrawalError(failure, 'Withdrawal requests could not be loaded. Try again.');
            }
            return false;
        } finally { if (requestVersion === version) loading.value = false; }
    }
    function invalidate() { version++; }
    return { rows, programs, canRequest, loading, error, pagination, load, invalidate };
}
