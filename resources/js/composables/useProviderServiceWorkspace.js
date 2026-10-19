import { computed, onMounted, ref } from 'vue';
import { providerServiceWorkspace } from '../support/providerServiceWorkspaces.js';

export function providerServicePurchaseId() {
    return document.getElementById('app')?.dataset.purchaseId
        ?? window.location.pathname.match(/\/provider\/billing\/(\d+)/)?.[1];
}

export function formatServiceMoney(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

export function formatServiceDateTime(value) {
    if (!value) return 'Not scheduled';
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export function localServiceDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

export function formatServiceFileSize(bytes) {
    if (!bytes) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function useProviderServiceWorkspace(section) {
    const purchaseId = providerServicePurchaseId();
    const purchase = ref(null);
    const isLoading = ref(true);
    const isSaving = ref(false);
    const errorMessage = ref('');
    const successMessage = ref('');
    const workspace = computed(() => providerServiceWorkspace(purchase.value?.plan_code));
    const canModify = computed(() => purchase.value?.status === 'paid'
        && !['provider_review', 'completed', 'cancelled'].includes(purchase.value?.fulfillment_status));
    const directoryUrl = computed(() => {
        if (!purchase.value || purchase.value.status !== 'paid') return '/provider/workspaces/billing/requests';
        if (['needs_information', 'provider_review'].includes(purchase.value.fulfillment_status)) return '/provider/workspaces/billing/action';
        if (purchase.value.fulfillment_status === 'in_progress') return '/provider/workspaces/billing/active';
        if (['completed', 'cancelled'].includes(purchase.value.fulfillment_status)) return '/provider/workspaces/billing/completed';
        return '/provider/workspaces/billing/waiting';
    });

    function applyPurchase(payload) {
        purchase.value = payload;
    }

    function actionError(error, fallback) {
        const validation = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
        errorMessage.value = validation || error.response?.data?.message || fallback;
        successMessage.value = '';
    }

    async function loadRecord() {
        isLoading.value = true;
        errorMessage.value = '';

        try {
            const response = await window.axios.get(`/provider/billing/${purchaseId}/data`, {
                params: { section },
            });
            applyPurchase(response.data.purchase);
        } catch (error) {
            errorMessage.value = error.response?.data?.message || 'This service request could not be loaded.';
        } finally {
            isLoading.value = false;
        }
    }

    async function runAction(action, successFallback) {
        isSaving.value = true;
        errorMessage.value = '';
        successMessage.value = '';

        try {
            const response = await action();
            applyPurchase(response.data.purchase);
            successMessage.value = response.data.message || successFallback;
            return true;
        } catch (error) {
            actionError(error, 'The service request could not be updated.');
            return false;
        } finally {
            isSaving.value = false;
        }
    }

    onMounted(loadRecord);

    return {
        purchaseId,
        purchase,
        workspace,
        isLoading,
        isSaving,
        errorMessage,
        successMessage,
        canModify,
        directoryUrl,
        applyPurchase,
        actionError,
        loadRecord,
        runAction,
    };
}
