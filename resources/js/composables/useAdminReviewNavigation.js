import { computed, onBeforeUnmount, ref } from 'vue';
import { createStaffQueueState, staffQueueLink, staffQueueReturn } from '../support/staffQueueState.js';

export function useAdminReviewNavigation(kind, recordId) {
    const fallback = `/admin/workspaces/reviews/${kind}`;
    const returned = staffQueueReturn(window, 'adminReviews', fallback);
    const queueUrl = new URL(returned, window.location.origin);
    const backUrl = queueUrl.pathname === fallback ? returned : fallback;
    const location = new URL(backUrl, window.location.origin);
    let sessionStorage;
    try { sessionStorage = window.sessionStorage; } catch { /* Queue links also work when browser storage is unavailable. */ }
    const scope = { location, sessionStorage, portalUser: window.portalUser, portalPathname: () => location.pathname };
    const state = createStaffQueueState(scope, ['search', 'status']);
    const next = ref(null);
    const loading = ref(false);
    const error = ref('');
    let controller;
    async function load() {
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal;
        loading.value = true; error.value = ''; next.value = null;
        const params = { search: state.get('search') || undefined, status: state.get('status') || (kind === 'programs' ? 'pending_review' : 'pending'), page: state.page, per_page: 10 };
        try {
            let response = await state.load((query) => window.axios.get(`${fallback}/data`, { params: query, signal, portalToast: false }), params, () => !signal.aborted);
            let rows = response.data.items ?? [];
            const index = rows.findIndex((row) => String(row.id) === String(recordId));
            let candidate = rows.slice(index + 1).find((row) => String(row.id) !== String(recordId));
            if (!candidate && response.data.pagination.current_page < response.data.pagination.last_page) {
                response = await window.axios.get(`${fallback}/data`, { params: { ...params, page: response.data.pagination.current_page + 1 }, signal, portalToast: false });
                candidate = response.data.items?.find((row) => String(row.id) !== String(recordId));
            }
            if (!signal.aborted && candidate?.review_url) {
                const returnUrl = new URL(fallback, window.location.origin);
                for (const [key, value] of Object.entries({ ...params, page: response.data.pagination.current_page })) {
                    if (value !== undefined && key !== 'per_page') returnUrl.searchParams.set(key, String(value));
                }
                next.value = { url: staffQueueLink(candidate.review_url, returnUrl.pathname + returnUrl.search, window.location.origin), name: candidate.name || candidate.title };
            }
        } catch (failure) {
            if (!signal.aborted) error.value = 'Next record unavailable.';
        } finally { if (!signal.aborted) loading.value = false; }
    }
    onBeforeUnmount(() => controller?.abort());
    return { backUrl, next, loading, error, load, nextLabel: computed(() => next.value ? `Next record: ${next.value.name}` : '') };
}
