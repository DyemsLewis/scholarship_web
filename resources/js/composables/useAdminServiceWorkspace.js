import { computed, onMounted, ref } from 'vue';

export function adminServicePurchaseId() {
    return document.getElementById('app')?.dataset.purchaseId
        ?? window.location.pathname.match(/\/admin\/workspaces\/billing\/requests\/(\d+)/)?.[1];
}

export function formatAdminServiceMoney(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

export function formatAdminServiceDateTime(value, fallback = 'Not set') {
    if (!value) return fallback;
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export function adminServiceDateInput(value) {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export function formatAdminServiceFileSize(bytes) {
    if (!bytes) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function useAdminServiceWorkspace(section) {
    const purchaseId = adminServicePurchaseId();
    const purchase = ref(null);
    const assignees = ref([]);
    const statusOptions = ref([]);
    const isLoading = ref(true);
    const isSaving = ref(false);
    const errorMessage = ref('');
    const successMessage = ref('');
    const queueUrl = computed(() => {
        const status = purchase.value?.fulfillment_status;
        if (status === 'in_progress') return '/admin/workspaces/billing/queues/active';
        if (['needs_information', 'provider_review'].includes(status)) return '/admin/workspaces/billing/queues/waiting';
        if (['completed', 'cancelled'].includes(status)) return '/admin/workspaces/billing/queues/completed';
        return '/admin/workspaces/billing/queues/ready';
    });

    function applyPurchase(payload) {
        purchase.value = payload;
    }

    function handleError(error, fallback) {
        const validation = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
        errorMessage.value = validation || error.response?.data?.message || fallback;
        successMessage.value = '';
    }

    async function loadRecord() {
        isLoading.value = true;
        errorMessage.value = '';
        try {
            const response = await window.axios.get(`/admin/billing/${purchaseId}/data`, { params: { section } });
            applyPurchase(response.data.purchase);
            assignees.value = response.data.assignees ?? [];
            statusOptions.value = response.data.status_options ?? [];
        } catch (error) {
            handleError(error, 'This service request could not be loaded.');
        } finally {
            isLoading.value = false;
        }
    }

    async function runAction(action, fallback) {
        if (isSaving.value) return false;
        isSaving.value = true;
        errorMessage.value = '';
        successMessage.value = '';
        try {
            const response = await action();
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
