<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSectionNav from '../components/ProviderSectionNav.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { showPortalToast } from '../support/portalToast';

const isLoading = ref(true);
const isOpeningCheckout = ref(false);
const errorMessage = ref('');
const organization = ref(null);
const gateway = ref({ configured: false, payment_methods: [] });
const plans = ref([]);
const purchases = ref([]);
const selectedPlan = ref(null);
const acceptsTerms = ref(false);
const syncingReference = ref('');

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
    }).format(Number(amount ?? 0) / 100);
}

function dateTime(value) {
    if (!value) {
        return 'Not yet';
    }

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function statusLabel(value) {
    return String(value ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function purchaseProgress(purchase) {
    if (purchase.status === 'failed') {
        return {
            label: 'Payment not confirmed',
            description: 'The request did not start. You can select the service again whenever it is needed.',
            percent: 0,
            step: 0,
            badgeClass: 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
            barClass: 'bg-rose-500',
        };
    }

    if (purchase.status !== 'paid') {
        return {
            label: 'Payment pending',
            description: 'Complete the secure checkout before support work can begin.',
            percent: 20,
            step: 1,
            badgeClass: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
            barClass: 'bg-amber-400',
        };
    }

    if (purchase.fulfillment_status === 'completed') {
        return {
            label: 'Service completed',
            description: purchase.fulfillment_notes || 'The support request has been completed.',
            percent: 100,
            step: 5,
            badgeClass: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
            barClass: 'bg-emerald-500',
        };
    }

    if (purchase.fulfillment_status === 'provider_review') {
        return {
            label: 'Ready for your review',
            description: 'Review the updates and deliverables, then confirm completion or request additional work.',
            percent: 90,
            step: 4,
            badgeClass: 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
            barClass: 'bg-sky-500',
        };
    }

    if (purchase.fulfillment_status === 'in_progress') {
        return {
            label: 'Support in progress',
            description: purchase.fulfillment_notes || 'The support team is currently working on this request.',
            percent: 75,
            step: 3,
            badgeClass: 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
            barClass: 'bg-sky-500',
        };
    }

    if (purchase.fulfillment_status === 'needs_information') {
        return {
            label: 'Information needed',
            description: purchase.fulfillment_notes || 'Platform support needs a response before work can continue.',
            percent: 35,
            step: 2,
            badgeClass: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
            barClass: 'bg-amber-400',
        };
    }

    return {
        label: 'Ready to start',
        description: purchase.fulfillment_notes || 'Payment is confirmed and the service brief is ready for assignment.',
        percent: 40,
        step: 2,
        badgeClass: 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
        barClass: 'bg-slate-700',
    };
}

function planIcon(code) {
    return {
        assisted_setup: 'fa-list-check',
        application_cycle_support: 'fa-users-gear',
        integration_consultation: 'fa-diagram-project',
    }[code] ?? 'fa-handshake-angle';
}

function paymentMethodsLabel(methods = []) {
    const labels = {
        card: 'Card',
        gcash: 'GCash',
        qrph: 'QR Ph',
    };

    return methods.map((method) => labels[method] ?? statusLabel(method)).join(', ');
}

function openPurchase(plan) {
    if (!gateway.value.configured) {
        showPortalToast({
            type: 'error',
            title: 'Payments unavailable',
            message: 'Online payment is temporarily unavailable. Please try again later or contact platform support.',
        });
        return;
    }

    selectedPlan.value = plan;
    acceptsTerms.value = false;
}

function closePurchase() {
    if (isOpeningCheckout.value) {
        return;
    }

    selectedPlan.value = null;
    acceptsTerms.value = false;
}

async function startCheckout() {
    if (!selectedPlan.value || !acceptsTerms.value || isOpeningCheckout.value) {
        return;
    }

    isOpeningCheckout.value = true;

    try {
        const response = await window.axios.post('/provider/billing/checkout', {
            plan_code: selectedPlan.value.code,
            accept_terms: acceptsTerms.value,
        }, {
            portalToast: false,
        });

        window.location.assign(response.data.checkout_url);
    } catch (error) {
        showPortalToast({
            type: 'error',
            title: 'Checkout unavailable',
            message: error.response?.data?.message ?? 'The payment page could not be opened. Please try again.',
        });
        isOpeningCheckout.value = false;
    }
}

async function loadBilling() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/billing/data');
        organization.value = response.data.organization;
        gateway.value = response.data.gateway ?? gateway.value;
        plans.value = response.data.plans ?? [];
        purchases.value = response.data.purchases ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider services.';
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

async function syncPayment(reference, { clearResult = false } = {}) {
    if (!reference || syncingReference.value) {
        return;
    }

    syncingReference.value = reference;

    try {
        const response = await window.axios.post('/provider/billing/sync', {
            reference,
        }, {
            portalToast: false,
        });
        const confirmed = Boolean(response.data.confirmed);

        showPortalToast({
            title: confirmed ? 'Payment confirmed' : 'Confirmation pending',
            message: response.data.message,
            duration: confirmed ? 5000 : 6000,
        });
    } catch (error) {
        showPortalToast({
            type: 'error',
            title: 'Unable to confirm payment',
            message: error.response?.data?.message ?? 'PayMongo could not be reached. The order remains pending.',
            duration: 6000,
        });
    } finally {
        syncingReference.value = '';

        if (clearResult) {
            clearCheckoutResult();
        }

        await loadBilling();
    }
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        closePurchase();
    }
}

onMounted(() => {
    const searchParams = new URLSearchParams(window.location.search);
    const checkoutResult = searchParams.get('checkout');
    const checkoutReference = searchParams.get('reference');

    if (checkoutResult === 'submitted' && checkoutReference) {
        syncPayment(checkoutReference, { clearResult: true });
    } else if (checkoutResult === 'cancelled') {
        showPortalToast({
            type: 'error',
            title: 'Checkout cancelled',
            message: 'No service will start unless PayMongo confirms a payment.',
        });
        clearCheckoutResult();
        loadBilling();
    } else {
        loadBilling();
    }

    window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase text-amber-700">Provider support</p>
                            <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">Support services for your team</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Request one-time help when {{ organization?.name ?? 'your organization' }} needs guidance with setup, application operations, or integration.
                            </p>
                        </div>
                        <div :class="['flex w-fit items-center gap-3 rounded-md border bg-white px-3.5 py-3 shadow-sm', gateway.configured ? 'border-emerald-200' : 'border-amber-200']">
                            <span :class="['grid h-9 w-9 place-items-center rounded-md', gateway.configured ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800']">
                                <i :class="['fa-solid', gateway.configured ? 'fa-lock' : 'fa-clock']" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold text-slate-900">Secure online payment</p>
                                <p :class="['mt-0.5 text-[11px] font-semibold', gateway.configured ? 'text-emerald-700' : 'text-amber-700']">
                                    {{ gateway.configured ? 'Available' : 'Temporarily unavailable' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </header>

                <ProviderSectionNav section="support" />

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">
                    Loading support services...
                </div>

                <template v-else>
                    <section class="mt-6 flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3.5 shadow-sm sm:items-center">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                            <i class="fa-solid fa-shield-heart" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-sm font-bold text-slate-950">Core portal tools remain free</p>
                            <p class="mt-0.5 text-xs leading-5 text-slate-500">Program publishing, applicant review, matching, and notifications are not affected by service purchases.</p>
                        </div>
                    </section>

                    <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase text-amber-700">Available support</p>
                                <h2 class="mt-1 text-xl font-bold text-slate-950">Choose the help you need</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Review the scope and one-time price before opening secure checkout.</p>
                            </div>
                            <p class="text-xs font-semibold text-slate-500">No subscription required</p>
                        </div>

                        <div class="grid gap-4 p-5 sm:p-6 lg:grid-cols-3">
                            <article v-for="plan in plans" :key="plan.code" class="flex h-full flex-col overflow-hidden rounded-md border border-slate-200 bg-white transition hover:border-slate-400 hover:shadow-md">
                                <div class="flex-1 p-4 sm:p-5">
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                                            <i :class="['fa-solid', planIcon(plan.code)]" aria-hidden="true"></i>
                                        </span>
                                        <span class="rounded-md bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase text-amber-800 ring-1 ring-amber-200">One-time</span>
                                    </div>

                                    <h3 class="mt-4 text-base font-bold text-slate-950">{{ plan.name }}</h3>
                                    <p class="mt-2 min-h-[3rem] text-sm leading-6 text-slate-500">{{ plan.description }}</p>

                                    <div v-if="plan.best_for" class="mt-4 min-h-[4.25rem] rounded-md border border-amber-100 bg-amber-50/70 px-3 py-2.5">
                                        <p class="text-[10px] font-bold uppercase text-amber-800">Best for</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-700">{{ plan.best_for }}</p>
                                    </div>

                                    <p class="mt-4 text-[10px] font-bold uppercase text-slate-500">What you receive</p>
                                    <ul class="mt-2 space-y-2">
                                        <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-2 text-sm leading-5 text-slate-700">
                                            <i class="fa-solid fa-check mt-1 text-[10px] text-emerald-600" aria-hidden="true"></i>
                                            <span>{{ feature }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <div class="border-t border-slate-200 bg-slate-50 px-4 py-4 sm:px-5">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase text-slate-500">One-time price</p>
                                            <p class="mt-1 text-xl font-black text-slate-950">{{ money(plan.amount, plan.currency) }}</p>
                                        </div>
                                        <i class="fa-solid fa-lock mt-1 text-xs text-slate-400" aria-hidden="true"></i>
                                    </div>
                                    <button type="button" class="mt-4 w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!gateway.configured" @click="openPurchase(plan)">
                                        {{ gateway.configured ? 'Review service' : 'Payment unavailable' }}
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                            <div>
                                <p class="text-xs font-bold uppercase text-amber-700">Your requests</p>
                                <h2 class="mt-1 text-xl font-bold text-slate-950">Payment and support progress</h2>
                                <p class="mt-1 text-sm leading-6 text-slate-500">Each request shows its current stage and what happens next.</p>
                            </div>
                            <span class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">{{ purchases.length }} request{{ purchases.length === 1 ? '' : 's' }}</span>
                        </div>

                        <div v-if="purchases.length === 0" class="flex items-center gap-4 p-5 sm:p-6">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-400">
                                <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-slate-900">No support requests yet</p>
                                <p class="mt-1 text-sm leading-5 text-slate-500">Choose a service above only when your team needs additional help.</p>
                            </div>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article v-for="purchase in purchases" :key="purchase.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50/70 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,1.35fr)_auto] lg:items-center">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">{{ purchase.plan_name }}</p>
                                    <p class="mt-1 font-mono text-[11px] text-slate-500">{{ purchase.reference_number }}</p>
                                </div>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span :class="['inline-flex rounded-md px-2 py-1 text-xs font-bold', purchaseProgress(purchase).badgeClass]">{{ purchaseProgress(purchase).label }}</span>
                                        <span v-if="purchaseProgress(purchase).step" class="text-[10px] font-bold uppercase text-slate-400">Step {{ purchaseProgress(purchase).step }} of 5</span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div :class="['h-full rounded-full transition-all', purchaseProgress(purchase).barClass]" :style="{ width: `${purchaseProgress(purchase).percent}%` }"></div>
                                    </div>
                                    <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ purchaseProgress(purchase).description }}</p>
                                </div>

                                <div class="lg:text-right">
                                    <p class="text-sm font-black text-slate-950">{{ money(purchase.amount, purchase.currency) }}</p>
                                    <p class="mt-1 whitespace-nowrap text-[11px] text-slate-500">{{ purchase.paid_at ? `Paid ${dateTime(purchase.paid_at)}` : `Requested ${dateTime(purchase.created_at)}` }}</p>
                                    <div v-if="purchase.status === 'pending'" class="mt-2 flex items-center gap-2 lg:justify-end">
                                        <a v-if="purchase.checkout_url" :href="purchase.checkout_url" class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                            Continue payment
                                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                        </a>
                                        <button
                                            type="button"
                                            :disabled="Boolean(syncingReference)"
                                            :class="[
                                                'inline-flex h-8 items-center justify-center rounded-md border border-slate-300 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50',
                                                purchase.checkout_url ? 'w-8' : 'px-3',
                                            ]"
                                            :aria-label="purchase.checkout_url ? 'Refresh payment status' : undefined"
                                            :title="purchase.checkout_url ? 'Refresh payment status' : undefined"
                                            @click="syncPayment(purchase.reference_number)"
                                        >
                                            <i v-if="purchase.checkout_url" :class="['fa-solid fa-rotate-right', syncingReference === purchase.reference_number ? 'animate-spin' : '']" aria-hidden="true"></i>
                                            <span v-else>{{ syncingReference === purchase.reference_number ? 'Checking...' : 'Check payment status' }}</span>
                                        </button>
                                    </div>
                                    <a v-else-if="purchase.status === 'paid'" :href="purchase.workspace_url" class="mt-2 inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100">
                                        Open workspace <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </article>
                        </div>
                    </section>
                </template>

                <ProviderFooter />
            </div>
        </section>
    </main>

    <div v-if="selectedPlan" class="fixed inset-0 z-[90] grid place-items-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-labelledby="service-checkout-title" @click.self="closePurchase">
        <section class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                        <i :class="['fa-solid', planIcon(selectedPlan.code)]" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase text-amber-700">Review service request</p>
                        <h2 id="service-checkout-title" class="mt-1 text-lg font-bold text-slate-950 sm:text-xl">{{ selectedPlan.name }}</h2>
                    </div>
                </div>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Close" @click="closePurchase">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="overflow-y-auto p-5 sm:p-6">
                <div class="flex items-end justify-between gap-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase text-slate-500">Total one-time price</p>
                        <p class="mt-1 text-xs font-semibold text-slate-600">Paid once through secure checkout</p>
                    </div>
                    <span class="text-xl font-black text-slate-950">{{ money(selectedPlan.amount, selectedPlan.currency) }}</span>
                </div>

                <div v-if="selectedPlan.best_for" class="mt-4 rounded-md border border-amber-100 bg-amber-50 px-4 py-3">
                    <p class="text-[10px] font-bold uppercase text-amber-800">Best for</p>
                    <p class="mt-1 text-sm leading-6 text-slate-700">{{ selectedPlan.best_for }}</p>
                </div>

                <section class="mt-4">
                    <p class="text-xs font-bold uppercase text-slate-500">Included in this request</p>
                    <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                        <li v-for="feature in selectedPlan.features" :key="feature" class="flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2.5 text-sm leading-5 text-slate-600">
                            <i class="fa-solid fa-check mt-1 text-[10px] text-emerald-600" aria-hidden="true"></i>
                            <span>{{ feature }}</span>
                        </li>
                    </ul>
                </section>

                <section class="mt-4 rounded-md bg-slate-950 p-4 text-white">
                    <p class="text-[10px] font-bold uppercase text-amber-300">What happens after payment</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <span class="grid h-6 w-6 place-items-center rounded bg-white/10 text-[10px] font-bold">1</span>
                            <p class="mt-2 text-xs font-bold">Payment confirmed</p>
                        </div>
                        <div>
                            <span class="grid h-6 w-6 place-items-center rounded bg-white/10 text-[10px] font-bold">2</span>
                            <p class="mt-2 text-xs font-bold">Choose a meeting time</p>
                        </div>
                        <div>
                            <span class="grid h-6 w-6 place-items-center rounded bg-white/10 text-[10px] font-bold">3</span>
                            <p class="mt-2 text-xs font-bold">Track support updates</p>
                        </div>
                    </div>
                </section>

                <label class="mt-5 flex cursor-pointer gap-3 rounded-md border border-slate-200 p-4 transition hover:bg-slate-50">
                    <input v-model="acceptsTerms" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-slate-900 focus:ring-amber-400">
                    <span class="text-sm leading-6 text-slate-600">
                        I agree to the service scope and understand that support starts only after payment is confirmed.
                    </span>
                </label>

                <p class="mt-4 flex items-start gap-2 text-xs leading-5 text-slate-500">
                    <i class="fa-solid fa-lock mt-1 text-[10px]" aria-hidden="true"></i>
                    <span>Secure checkout opens through PayMongo using {{ paymentMethodsLabel(gateway.payment_methods) || 'the available payment methods' }}. Payment details are not stored in this portal.</span>
                </p>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <button type="button" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="closePurchase">Cancel</button>
                    <button type="button" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!acceptsTerms || isOpeningCheckout" @click="startCheckout">
                        {{ isOpeningCheckout ? 'Opening checkout...' : 'Continue to PayMongo' }}
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
