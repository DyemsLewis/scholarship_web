import { ref } from 'vue';
import { concernError } from '../support/agreementConcerns.js';

export function useConcernCollection({ endpoint, params = () => ({}), fetcher }) {
    const rows = ref([]), options = ref([]), createUrl = ref(null), programs = ref([]);
    const loading = ref(false), error = ref('');
    const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
    let version = 0;
    let requestedPage = 1;
    function invalidate() { version++; createUrl.value = null; }
    async function load(page = 1) {
        requestedPage = page;
        const token = ++version;
        loading.value = true; error.value = ''; createUrl.value = null;
        try {
            const { data } = await fetcher(endpoint(), { params: { ...params(), page }, portalToast: false });
            if (token !== version) return false;
            rows.value = data.cases?.data ?? []; options.value = data.options ?? [];
            createUrl.value = data.create_url ?? null; programs.value = data.programs ?? [];
            const { current_page = 1, last_page = 1, total = 0 } = data.cases ?? {};
            pagination.value = { current_page, last_page, total };
            return true;
        } catch (failure) {
            if (token === version) { rows.value = []; options.value = []; error.value = concernError(failure, 'Agreement concerns could not be loaded.'); }
            return false;
        } finally { if (token === version) loading.value = false; }
    }
    function retry() {
        if (error.value && !loading.value) return load(requestedPage);
    }
    return { rows, options, createUrl, programs, loading, error, pagination, load, retry, invalidate };
}
