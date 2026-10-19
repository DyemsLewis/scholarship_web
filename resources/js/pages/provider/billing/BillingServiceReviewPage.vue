<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const requestId = window.location.pathname.match(/\/provider\/workspaces\/billing\/service-requests\/(\d+)\/review$/)?.[1] ?? '';
const isLoading = ref(true);
const isOpeningCheckout = ref(false);
const isSyncing = ref(false);
const errorMessage = ref('');
const notice = ref(null);
const acceptTerms = ref(false);
const plan = ref(null);
const serviceRequest = ref(null);
const gateway = ref({ name: 'PayMongo', configured: false, payment_methods: [] });

const purchase = computed(() => serviceRequest.value?.purchase ?? null);
const paymentConfirmed = computed(() => purchase.value?.status === 'paid');
const existingCheckout = computed(() => purchase.value?.status === 'pending' && purchase.value?.checkout_url);
const canStartCheckout = computed(() => gateway.value.configured && acceptTerms.value && !paymentConfirmed.value);

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

function paymentMethods(methods = []) {
    const labels = { card: 'Card', gcash: 'GCash', qrph: 'QR Ph' };
    return methods.map((method) => labels[method] ?? method).join(', ');
}

async function loadReview() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/workspaces/billing/service-requests/${requestId}/review/data`);
        plan.value = response.data.plan ?? null;
        serviceRequest.value = response.data.request ?? null;
        gateway.value = response.data.gateway ?? gateway.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The service request could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

function clearCheckoutResult() {
    const url = new URL(window.location.href);
    url.searchParams.delete('checkout');
    url.searchParams.delete('reference');
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}

async function syncPayment(reference) {
    isSyncing.value = true;

    try {
        const response = await window.axios.post('/provider/billing/sync', { reference }, { portalToast: false });
        notice.value = {
            type: response.data.confirmed ? 'success' : 'info',
            message: response.data.message,
        };
        await loadReview();
    } catch (error) {
        notice.value = {
            type: 'error',
            message: error.response?.data?.message || 'Payment confirmation is temporarily unavailable.',
        };
    } finally {
        isSyncing.value = false;
        clearCheckoutResult();
    }
}

async function startCheckout() {
    if (!canStartCheckout.value) return;

    isOpeningCheckout.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.post(`/provider/workspaces/billing/service-requests/${requestId}/checkout`, {
            accept_terms: acceptTerms.value,
        });

        if (response.data.confirmed && response.data.purchase?.workspace_url) {
            window.location.assign(response.data.purchase.workspace_url);
            return;
        }

        window.location.assign(response.data.checkout_url);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The secure checkout could not be opened.';
        isOpeningCheckout.value = false;
    }
}

onMounted(async () => {
    const query = new URLSearchParams(window.location.search);
    const checkout = query.get('checkout');
    const reference = query.get('reference');

    await loadReview();

    if (checkout === 'submitted' && reference) await syncPayment(reference);
    if (checkout === 'cancelled') {
        notice.value = {
            type: 'info',
            message: 'Checkout was cancelled. Your reviewed request is saved and no service has started.',
        };
        clearCheckoutResult();
    }
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="review-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/billing/services" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to services
                </a>

                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm text-slate-500">Loading request review...</div>

                <div v-else-if="!plan || !serviceRequest" class="mt-4 border border-rose-200 bg-rose-50 px-6 py-8 text-center text-sm font-semibold text-rose-800">
                    {{ errorMessage || 'This service request is not available.' }}
                </div>

                <template v-else>
                    <div class="mt-4 grid grid-cols-3 border border-slate-200 bg-white shadow-sm" aria-label="Request progress">
                        <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-4 text-slate-700">
                            <span class="grid h-8 w-8 place-items-center bg-slate-950 text-xs font-bold text-white"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                            <p class="text-sm font-bold">Request details</p>
                        </div>
                        <div class="flex items-center gap-3 border-r border-slate-200 bg-amber-50 px-5 py-4">
                            <span class="grid h-8 w-8 place-items-center bg-amber-400 text-xs font-bold text-slate-950">2</span>
                            <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Current step</p><p class="text-sm font-bold text-slate-950">Review</p></div>
                        </div>
                        <div class="flex items-center gap-3 px-5 py-4 text-slate-500">
                            <span class="grid h-8 w-8 place-items-center bg-slate-100 text-xs font-bold">3</span>
                            <p class="text-sm font-bold">Payment</p>
                        </div>
                    </div>

                    <header class="mt-4 flex items-start justify-between gap-8 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Review before payment</p>
                            <h1 id="review-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ plan.name }}</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Confirm the service details and contact before opening checkout.</p>
                        </div>
                        <a v-if="serviceRequest.editable" :href="serviceRequest.edit_url" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950">
                            <i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>
                            Edit details
                        </a>
                    </header>

                    <div v-if="notice" :class="['mt-4 border px-5 py-4 text-sm font-semibold', notice.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : notice.type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-300 bg-white text-slate-700']">
                        {{ notice.message }}
                    </div>
                    <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
                            <div><h2 class="text-lg font-bold text-slate-950">Request summary</h2><p class="mt-1 text-xs text-slate-500">These details become the starting brief after payment.</p></div>
                            <div class="text-right"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">One-time fee</p><p class="mt-1 text-xl font-bold text-slate-950">{{ money(plan.amount, plan.currency) }}</p></div>
                        </header>

                        <dl class="divide-y divide-slate-200 px-6">
                            <div v-for="item in serviceRequest.review_items" :key="item.label" class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 py-4">
                                <dt class="text-sm font-semibold text-slate-500">{{ item.label }}</dt>
                                <dd class="text-sm font-semibold leading-6 text-slate-900">{{ item.value }}</dd>
                            </div>
                        </dl>

                        <div class="border-t border-slate-200 px-6 py-5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Requested outcome</p>
                            <p class="mt-2 max-w-4xl text-sm font-semibold leading-6 text-slate-900">{{ serviceRequest.requested_outcome }}</p>
                        </div>

                        <div class="border-t border-slate-200 px-6 py-5">
                            <h3 class="text-sm font-bold text-slate-950">Service contact</h3>
                            <dl class="mt-3 grid grid-cols-2 gap-6">
                                <div v-for="item in serviceRequest.contact" :key="item.label"><dt class="text-xs font-semibold text-slate-500">{{ item.label }}</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ item.value }}</dd></div>
                            </dl>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                        <header class="border-b border-slate-200 px-6 py-5">
                            <h2 class="text-lg font-bold text-slate-950">Payment</h2>
                            <p class="mt-1 text-sm text-slate-600">The service enters the platform queue only after payment is confirmed.</p>
                        </header>

                        <div v-if="paymentConfirmed" class="flex items-center justify-between px-6 py-5">
                            <div><p class="text-sm font-bold text-emerald-800">Payment confirmed</p><p class="mt-1 text-xs text-slate-500">Your service request is ready for platform support.</p></div>
                            <a :href="purchase.workspace_url" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white">Open service request <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                        </div>

                        <div v-else-if="existingCheckout" class="flex items-center justify-between px-6 py-5">
                            <div><p class="text-sm font-bold text-slate-900">Checkout is waiting for payment</p><p class="mt-1 text-xs text-slate-500">You can safely continue the existing PayMongo checkout.</p></div>
                            <a :href="purchase.checkout_url" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white">Continue payment <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i></a>
                        </div>

                        <div v-else class="px-6 py-5">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input v-model="acceptTerms" type="checkbox" class="mt-0.5 h-4 w-4 accent-slate-950">
                                <span class="text-sm leading-6 text-slate-700">I confirm that the request details are correct, agree to the service scope, and understand that work begins after payment.</span>
                            </label>

                            <div class="mt-5 flex items-center justify-between border-t border-slate-200 pt-5">
                                <div>
                                    <p class="text-sm font-bold text-slate-900">Secure checkout through {{ gateway.name || 'PayMongo' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ gateway.configured ? `Available methods: ${paymentMethods(gateway.payment_methods)}` : 'Online payment is temporarily unavailable.' }}</p>
                                </div>
                                <button type="button" :disabled="!canStartCheckout || isOpeningCheckout || isSyncing" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40" @click="startCheckout">
                                    {{ isOpeningCheckout ? 'Opening checkout...' : 'Continue to PayMongo' }}
                                    <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
