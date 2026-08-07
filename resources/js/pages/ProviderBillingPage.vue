<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { showPortalToast } from '../support/portalToast';

const isLoading = ref(true);
const isOpeningCheckout = ref(false);
const errorMessage = ref('');
const organization = ref(null);
const gateway = ref({ configured: false, mode: 'test', payment_methods: [] });
const freeCore = ref([]);
const plans = ref([]);
const purchases = ref([]);
const selectedPlan = ref(null);
const acceptsTerms = ref(false);

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

function paymentStatusClass(status) {
    if (status === 'paid') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'failed') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function fulfillmentStatusClass(status) {
    if (status === 'completed') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'in_progress') {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function openPurchase(plan) {
    if (!gateway.value.configured) {
        showPortalToast({
            type: 'error',
            title: 'Payments unavailable',
            message: 'PayMongo test keys and the signed webhook must be configured first.',
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
        freeCore.value = response.data.free_core ?? [];
        plans.value = response.data.plans ?? [];
        purchases.value = response.data.purchases ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider services.';
    } finally {
        isLoading.value = false;
    }
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        closePurchase();
    }
}

onMounted(() => {
    const checkoutResult = new URLSearchParams(window.location.search).get('checkout');

    if (checkoutResult === 'submitted') {
        showPortalToast({
            title: 'Payment submitted',
            message: 'The order will update after PayMongo securely confirms the payment.',
            duration: 6000,
        });
    } else if (checkoutResult === 'cancelled') {
        showPortalToast({
            type: 'error',
            title: 'Checkout cancelled',
            message: 'No service will start unless PayMongo confirms a payment.',
        });
    }

    window.addEventListener('keydown', handleKeydown);
    loadBilling();
});

onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Provider Services</p>
                            <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">Support when your team needs it</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Core portal tools remain free. Choose optional one-time help only when {{ organization?.name ?? 'your organization' }} needs extra support.
                            </p>
                        </div>
                        <div class="w-fit rounded-md border border-slate-200 bg-white px-3 py-2 shadow-sm">
                            <p :class="['flex items-center gap-2 text-xs font-bold', gateway.configured ? 'text-emerald-700' : 'text-amber-700']">
                                <span :class="['h-2 w-2 rounded-full', gateway.configured ? 'bg-emerald-500' : 'bg-amber-500']"></span>
                                {{ gateway.configured ? 'Checkout ready' : 'Setup required' }}
                            </p>
                            <p class="mt-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">PayMongo {{ gateway.mode }} mode</p>
                        </div>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">
                    Loading provider services...
                </div>

                <template v-else>
                    <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[minmax(0,1fr)_19rem]">
                            <div class="p-5 sm:p-6">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                                        <i class="fa-solid fa-shield-heart" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Always included</p>
                                        <h2 class="mt-1 text-lg font-bold text-slate-950">Your core workspace stays free</h2>
                                        <p class="mt-1 text-sm leading-6 text-slate-500">Payment never changes program visibility, matching, or approval decisions.</p>
                                    </div>
                                </div>

                                <ul class="mt-5 grid gap-3 sm:grid-cols-3">
                                    <li v-for="item in freeCore" :key="item" class="flex items-start gap-2 text-sm leading-5 text-slate-700">
                                        <i class="fa-solid fa-circle-check mt-0.5 text-emerald-600" aria-hidden="true"></i>
                                        <span>{{ item }}</span>
                                    </li>
                                </ul>
                            </div>

                            <aside class="border-t border-slate-200 bg-slate-50 p-5 lg:border-l lg:border-t-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Secure checkout</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <i class="fa-solid fa-lock text-sm text-slate-700" aria-hidden="true"></i>
                                    <p class="text-sm font-bold text-slate-900">PayMongo hosted payment</p>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-500">{{ paymentMethodsLabel(gateway.payment_methods) || 'Payment methods will appear when configured.' }}</p>
                                <p v-if="!gateway.configured" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
                                    Test credentials and the webhook secret are required before checkout can open.
                                </p>
                                <p v-else class="mt-3 flex items-center gap-2 text-xs font-semibold text-emerald-700">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    Test checkout is available
                                </p>
                            </aside>
                        </div>
                    </section>

                    <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Optional support</p>
                            <h2 class="mt-1 text-xl font-bold text-slate-950">Choose a one-time service</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Each service has a clear scope and does not affect your access to the provider portal.</p>
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-3">
                            <article v-for="plan in plans" :key="plan.code" class="flex min-h-full flex-col rounded-md border border-slate-200 bg-white p-4 transition hover:border-slate-400 hover:shadow-sm sm:p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-800">
                                        <i :class="['fa-solid', planIcon(plan.code)]" aria-hidden="true"></i>
                                    </span>
                                    <span class="rounded bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.1em] text-amber-800">One-time</span>
                                </div>

                                <h3 class="mt-4 text-base font-bold text-slate-950">{{ plan.name }}</h3>
                                <p class="mt-2 min-h-[3rem] text-sm leading-6 text-slate-500">{{ plan.description }}</p>

                                <ul class="mt-4 flex-1 space-y-2 border-t border-slate-100 pt-4">
                                    <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-2 text-sm leading-5 text-slate-700">
                                        <i class="fa-solid fa-check mt-1 text-[10px] text-emerald-600" aria-hidden="true"></i>
                                        <span>{{ feature }}</span>
                                    </li>
                                </ul>

                                <div class="mt-5 border-t border-slate-200 pt-4">
                                    <div class="flex items-end justify-between gap-3">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Service price</p>
                                            <p class="mt-1 text-xl font-black text-slate-950">{{ money(plan.amount, plan.currency) }}</p>
                                        </div>
                                        <i class="fa-solid fa-arrow-right text-sm text-slate-300" aria-hidden="true"></i>
                                    </div>
                                    <button type="button" class="mt-4 w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!gateway.configured" @click="openPurchase(plan)">
                                        {{ gateway.configured ? 'Select service' : 'Checkout unavailable' }}
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Service history</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Orders and progress</h2>
                            </div>
                            <span class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-600">{{ purchases.length }} records</span>
                        </div>

                        <div v-if="purchases.length === 0" class="flex items-center gap-4 p-5 sm:p-6">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-400">
                                <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-sm font-bold text-slate-900">No optional services purchased</p>
                                <p class="mt-1 text-sm leading-5 text-slate-500">Continue using the free portal and request support only when it is useful.</p>
                            </div>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article v-for="purchase in purchases" :key="purchase.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50/70 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.5fr)_minmax(8rem,.7fr)_minmax(8rem,.7fr)_auto] lg:items-center">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">{{ purchase.plan_name }}</p>
                                    <p class="mt-1 font-mono text-[11px] text-slate-500">{{ purchase.reference_number }}</p>
                                    <p v-if="purchase.fulfillment_notes" class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ purchase.fulfillment_notes }}</p>
                                </div>

                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Payment</p>
                                    <span :class="['mt-1 inline-flex rounded px-2 py-1 text-xs font-bold', paymentStatusClass(purchase.status)]">{{ statusLabel(purchase.status) }}</span>
                                </div>

                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Service</p>
                                    <span :class="['mt-1 inline-flex rounded px-2 py-1 text-xs font-bold', fulfillmentStatusClass(purchase.fulfillment_status)]">{{ statusLabel(purchase.fulfillment_status) }}</span>
                                </div>

                                <div class="sm:text-right">
                                    <p class="text-sm font-black text-slate-950">{{ money(purchase.amount, purchase.currency) }}</p>
                                    <p class="mt-1 whitespace-nowrap text-[11px] text-slate-500">{{ dateTime(purchase.paid_at ?? purchase.created_at) }}</p>
                                    <a v-if="purchase.checkout_url" :href="purchase.checkout_url" class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-sky-700 hover:underline">
                                        Continue checkout
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
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
        <section class="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-2xl">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                        <i :class="['fa-solid', planIcon(selectedPlan.code)]" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Confirm service</p>
                        <h2 id="service-checkout-title" class="mt-1 text-lg font-bold text-slate-950 sm:text-xl">{{ selectedPlan.name }}</h2>
                    </div>
                </div>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Close" @click="closePurchase">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="p-5 sm:p-6">
                <div class="flex items-end justify-between gap-4 rounded-md bg-slate-50 px-4 py-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">One-time service</p>
                        <p class="mt-1 text-sm font-semibold text-slate-700">Paid through PayMongo</p>
                    </div>
                    <span class="text-xl font-black text-slate-950">{{ money(selectedPlan.amount, selectedPlan.currency) }}</span>
                </div>

                <ul class="mt-4 space-y-2">
                    <li v-for="feature in selectedPlan.features" :key="feature" class="flex items-start gap-2 text-sm leading-5 text-slate-600">
                        <i class="fa-solid fa-check mt-1 text-[10px] text-emerald-600" aria-hidden="true"></i>
                        <span>{{ feature }}</span>
                    </li>
                </ul>

                <label class="mt-5 flex cursor-pointer gap-3 rounded-md border border-slate-200 p-4 transition hover:bg-slate-50">
                    <input v-model="acceptsTerms" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-slate-900 focus:ring-amber-400">
                    <span class="text-sm leading-6 text-slate-600">
                        I agree that this optional service starts only after payment confirmation and does not affect scholarship visibility or decisions.
                    </span>
                </label>

                <p class="mt-4 flex items-start gap-2 text-xs leading-5 text-slate-500">
                    <i class="fa-solid fa-lock mt-1 text-[10px]" aria-hidden="true"></i>
                    <span>PayMongo handles the payment page. Card and e-wallet details are not stored in this portal.</span>
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
