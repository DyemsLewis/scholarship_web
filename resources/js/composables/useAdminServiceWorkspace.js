import { computed, onMounted, ref } from 'vue';
import { useAdminResource } from './useAdminResource.js';

export function adminServicePurchaseId() {
    return document.getElementById('app')?.dataset.purchaseId
        ?? (window.portalPathname?.() ?? window.location.pathname).match(/\/admin\/workspaces\/billing\/requests\/(\d+)/)?.[1];
}

export function formatAdminServiceMoney(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

export function formatAdminServiceDateTime(value, fallback = 'Not set') {
    if (!value || Number.isNaN(new Date(value).getTime())) return fallback;
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'Asia/Manila',
    }).format(new Date(value));
}

export function adminServiceDateInput(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
    const part = (type) => parts.find((item) => item.type === type).value;
    return `${part('year')}-${part('month')}-${part('day')}`;
}

export function adminServiceTargetDateError(value, saved, today = adminServiceDateInput(new Date())) {
    if (!value) return '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || adminServiceDateInput(`${value}T00:00:00+08:00`) !== value) return 'Choose a valid target date.';
    return value < today && value !== adminServiceDateInput(saved)
        ? 'Keep the saved overdue date or choose today or a future target date.' : '';
}

export function useAdminServiceWorkspace(section) {
    const purchaseId = adminServicePurchaseId();
    const purchase = ref(null);
    const assignees = ref([]);
    const statusOptions = ref([]);
    const resource = useAdminResource(async ({ signal }) => {
        const response = await window.axios.get(`/admin/billing/${purchaseId}/data`, { params: { section }, signal });
        if (!response.data?.purchase) throw new Error('Missing service request.');
        return response.data;
    }, { failureMessage: 'This service request could not be loaded.' });
    const { isLoading, errorMessage: loadError } = resource;
    const isSaving = ref(false);
    const errorMessage = ref('');
    const successMessage = ref('');
    const ready = computed(() => Boolean(purchase.value) && !isLoading.value && !loadError.value);
    const canEdit = computed(() => ready.value && purchase.value.status === 'paid'
        && !['completed', 'cancelled'].includes(purchase.value.fulfillment_status)
        && purchase.value.cancellation_status !== 'requested');
    const queueUrl = computed(() => {
        const status = purchase.value?.fulfillment_status;
        if (status === 'in_progress') return '/admin/workspaces/billing/queues/active';
        if (['needs_information', 'provider_review'].includes(status)) return '/admin/workspaces/billing/queues/waiting';
        if (['completed', 'cancelled'].includes(status)) return '/admin/workspaces/billing/queues/completed';
        return '/admin/workspaces/billing/queues/ready';
    });

    function applyPurchase(payload) {
        purchase.value = payload;
        if (Array.isArray(payload?.delivery_status_options)) statusOptions.value = payload.delivery_status_options;
    }

    function handleError(error, fallback) {
        const validation = Object.values(error?.response?.data?.errors ?? {})[0]?.[0];
        errorMessage.value = validation || error?.response?.data?.message || fallback;
        successMessage.value = '';
    }

    async function loadRecord() {
        if (isSaving.value) return;
        errorMessage.value = '';
        const payload = await resource.load();
        if (!payload) return;
        applyPurchase(payload.purchase);
        assignees.value = payload.assignees ?? [];
        statusOptions.value = payload.status_options ?? [];
    }

    async function runAction(action, fallback) {
        if (isSaving.value || !ready.value) return false;
        isSaving.value = true;
        errorMessage.value = '';
        successMessage.value = '';
        try {
            const response = await action({ portalToast: false });
            applyPurchase(response.data.purchase);
            successMessage.value = response.data.message || fallback;
            return true;
        } catch (error) {
            handleError(error, 'The service request could not be updated.');
            return false;
        } finally {
            isSaving.value = false;
        }
    }

    onMounted(loadRecord);

    return {
        purchaseId,
        purchase,
        assignees,
        statusOptions,
        isLoading,
        loadError,
        ready,
        canEdit,
        isSaving,
        errorMessage,
        successMessage,
        queueUrl,
        applyPurchase,
        handleError,
        loadRecord,
        runAction,
    };
}
