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
                            <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">Optional operational support</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Purchase help only when {{ organization?.name ?? 'your organization' }} needs it. Core portal tools remain free.
                            </p>
                        </div>
                        <span :class="['w-fit rounded-md px-3 py-2 text-xs font-bold uppercase tracking-wide', gateway.mode === 'live' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
                            PayMongo {{ gateway.mode }} mode
                        </span>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">
                    Loading provider services...
                </div>

                <template v-else>
                    <section class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_18rem]">
                        <div class="grid gap-4 md:grid-cols-3">
                            <article v-for="plan in plans" :key="plan.code" class="flex min-h-full flex-col rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                                        <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i>
                                    </span>
                                    <p class="text-lg font-black text-slate-950">{{ money(plan.amount, plan.currency) }}</p>
                                </div>
                                <h2 class="mt-4 text-lg font-bold text-slate-950">{{ plan.name }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ plan.description }}</p>
                                <ul class="mt-4 space-y-2 text-sm text-slate-600">
                                    <li v-for="feature in plan.features" :key="feature" class="flex gap-2">
                                        <i class="fa-solid fa-check mt-1 text-xs text-emerald-600" aria-hidden="true"></i>
                                        <span>{{ feature }}</span>
                                    </li>
                                </ul>
                                <button type="button" class="mt-auto rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!gateway.configured" @click="openPurchase(plan)">
                                    {{ gateway.configured ? 'Pay securely' : 'Payments not configured' }}
                                </button>
                            </article>
                        </div>

                        <aside class="rounded-lg border border-slate-200 bg-slate-950 p-5 text-white shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Always included</p>
                            <h2 class="mt-2 text-xl font-bold">The portal stays free to use</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-300">Paying never changes program ranking, applicant matching, or admin approval.</p>
                            <ul class="mt-5 space-y-3">
                                <li v-for="item in freeCore" :key="item" class="flex gap-3 text-sm leading-5 text-slate-200">
                                    <i class="fa-solid fa-circle-check mt-0.5 text-amber-300" aria-hidden="true"></i>
                                    <span>{{ item }}</span>
                                </li>
                            </ul>
                            <p v-if="!gateway.configured" class="mt-5 rounded-md border border-amber-300/30 bg-amber-300/10 p-3 text-xs leading-5 text-amber-100">
                                Add PayMongo test credentials and webhook signing secret to enable checkout safely.
                            </p>
                        </aside>
                    </section>

                    <section class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Service history</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-950">Payments and fulfillment</h2>
                        </div>

                        <div v-if="purchases.length === 0" class="p-8 text-center">
                            <i class="fa-solid fa-receipt text-2xl text-slate-300" aria-hidden="true"></i>
                            <p class="mt-3 text-sm font-bold text-slate-900">No optional services purchased</p>
                            <p class="mt-1 text-sm text-slate-500">Use the free portal normally and purchase support only when needed.</p>
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-[0.12em] text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3 font-bold">Service</th>
                                        <th class="px-5 py-3 font-bold">Payment</th>
                                        <th class="px-5 py-3 font-bold">Service status</th>
                                        <th class="px-5 py-3 font-bold">Amount</th>
                                        <th class="px-5 py-3 font-bold">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="purchase in purchases" :key="purchase.id" class="align-top">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-950">{{ purchase.plan_name }}</p>
                                            <p class="mt-1 font-mono text-xs text-slate-500">{{ purchase.reference_number }}</p>
                                            <p v-if="purchase.fulfillment_notes" class="mt-2 max-w-md text-xs leading-5 text-slate-500">{{ purchase.fulfillment_notes }}</p>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span :class="['rounded px-2 py-1 text-xs font-bold', paymentStatusClass(purchase.status)]">{{ statusLabel(purchase.status) }}</span>
                                            <a v-if="purchase.checkout_url" :href="purchase.checkout_url" class="mt-2 block text-xs font-bold text-sky-700 hover:underline">Continue checkout</a>
                                        </td>
                                        <td class="px-5 py-4">
                                            <span :class="['rounded px-2 py-1 text-xs font-bold', fulfillmentStatusClass(purchase.fulfillment_status)]">{{ statusLabel(purchase.fulfillment_status) }}</span>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 font-bold text-slate-900">{{ money(purchase.amount, purchase.currency) }}</td>
                                        <td class="whitespace-nowrap px-5 py-4 text-slate-600">{{ dateTime(purchase.paid_at ?? purchase.created_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <ProviderFooter />
            </div>
        </section>
    </main>

    <div v-if="selectedPlan" class="fixed inset-0 z-[90] grid place-items-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-labelledby="service-checkout-title" @click.self="closePurchase">
        <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Optional provider service</p>
                    <h2 id="service-checkout-title" class="mt-2 text-xl font-bold text-slate-950">{{ selectedPlan.name }}</h2>
                </div>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50" aria-label="Close" @click="closePurchase">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="mt-5 flex items-center justify-between rounded-md bg-slate-50 px-4 py-3">
                <span class="text-sm font-semibold text-slate-600">One-time payment</span>
                <span class="text-lg font-black text-slate-950">{{ money(selectedPlan.amount, selectedPlan.currency) }}</span>
            </div>

            <label class="mt-5 flex cursor-pointer gap-3 rounded-md border border-slate-200 p-4">
                <input v-model="acceptsTerms" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-amber-400">
                <span class="text-sm leading-6 text-slate-600">
                    I understand this is an optional support service, does not affect scholarship visibility or decisions, and starts only after PayMongo confirms payment.
                </span>
            </label>

            <p class="mt-4 text-xs leading-5 text-slate-500">You will continue on PayMongo's hosted checkout. Card or e-wallet details are not stored by this portal.</p>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="closePurchase">Cancel</button>
                <button type="button" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!acceptsTerms || isOpeningCheckout" @click="startCheckout">
                    {{ isOpeningCheckout ? 'Opening checkout...' : 'Continue to PayMongo' }}
                </button>
            </div>
        </section>
    </div>
</template>
