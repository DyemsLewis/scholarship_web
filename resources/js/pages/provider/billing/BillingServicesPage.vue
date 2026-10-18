<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const planCode = window.location.pathname.match(/\/provider\/workspaces\/billing\/services\/([^/]+)$/)?.[1] ?? null;
const isLoading = ref(true);
const isOpeningCheckout = ref(false);
const syncingReference = ref('');
const errorMessage = ref('');
const notice = ref(null);
const organization = ref(null);
const gateway = ref({ configured: false, payment_methods: [] });
const plans = ref([]);
const purchases = ref([]);
const form = reactive({ accept_terms: false, support_areas: [], support_note: '' });

const selectedPlan = computed(() => plans.value.find((plan) => plan.code === planCode) ?? null);
const needsSupportScope = computed(() => selectedPlan.value?.code === 'application_cycle_support');
const canStartCheckout = computed(() => gateway.value.configured
    && form.accept_terms
    && (!needsSupportScope.value || form.support_areas.length > 0));

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency, maximumFractionDigits: 0 }).format((amount || 0) / 100);
}

function paymentMethods(methods = []) {
    const labels = { card: 'Card', gcash: 'GCash', qrph: 'QR Ph' };
    return methods.map((method) => labels[method] ?? method).join(', ');
}

function toggleSupportArea(value) {
    form.support_areas = form.support_areas.includes(value)
        ? form.support_areas.filter((area) => area !== value)
        : [...form.support_areas, value];
}

async function loadServices() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/billing/data');
        organization.value = response.data.organization;
        gateway.value = response.data.gateway ?? gateway.value;
        plans.value = response.data.plans ?? [];
        purchases.value = response.data.purchases ?? [];
        if (planCode && !plans.value.some((plan) => plan.code === planCode)) errorMessage.value = 'This optional service is not available.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Optional services could not be loaded.';
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
    syncingReference.value = reference;
    try {
        const response = await window.axios.post('/provider/billing/sync', { reference });
        notice.value = { type: response.data.confirmed ? 'success' : 'info', message: response.data.message };
    } catch (error) {
        notice.value = { type: 'error', message: error.response?.data?.message || 'Payment confirmation is temporarily unavailable.' };
    } finally {
        syncingReference.value = '';
        clearCheckoutResult();
        await loadServices();
    }
}

async function startCheckout() {
    if (!canStartCheckout.value || !selectedPlan.value) return;
    isOpeningCheckout.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post('/provider/billing/checkout', {
            plan_code: selectedPlan.value.code,
            accept_terms: form.accept_terms,
            ...(needsSupportScope.value ? { support_areas: form.support_areas, support_note: form.support_note.trim() } : {}),
        });
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
    await loadServices();
    if (checkout === 'submitted' && reference) await syncPayment(reference);
    if (checkout === 'cancelled') {
        notice.value = { type: 'info', message: 'Checkout was cancelled. No service starts until payment is confirmed.' };
        clearCheckoutResult();
    }
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" :aria-labelledby="selectedPlan ? 'service-title' : 'services-title'">
            <div class="mx-auto max-w-[94rem]">
                <template v-if="!planCode">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div class="flex items-start justify-between gap-8"><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Platform assistance</p><h1 id="services-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">Optional support services</h1><p class="mt-2 text-sm leading-6 text-slate-600">Choose one-time help only when your team needs it.</p></div><a href="/provider/workspaces/billing" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Request desk</a></div>
                    </header>

                    <div v-if="notice" :class="['mt-4 border px-5 py-4 text-sm font-semibold', notice.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : notice.type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-300 bg-white text-slate-700']">{{ notice.message }}</div>
                    <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <div v-if="isLoading" class="px-6 py-16 text-center text-sm text-slate-500">Loading support services...</div>
                        <div v-else class="divide-y divide-slate-200">
                            <article v-for="plan in plans" :key="plan.code" class="flex items-center gap-6 px-6 py-6 hover:bg-slate-50">
                                <span class="grid h-11 w-11 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1"><h2 class="text-lg font-bold text-slate-950">{{ plan.name }}</h2><p class="mt-1 text-sm leading-6 text-slate-600">{{ plan.description }}</p><p v-if="plan.best_for" class="mt-1 text-xs font-semibold text-slate-500">Best for: {{ plan.best_for }}</p></div>
                                <div class="w-44 shrink-0 border-l border-slate-200 pl-6"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">One-time fee</p><p class="mt-1 text-lg font-bold text-slate-950">{{ money(plan.amount, plan.currency) }}</p></div>
                                <a :href="`/provider/workspaces/billing/services/${plan.code}`" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">View service <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                            </article>
                        </div>
                    </section>
                    <p v-if="organization" class="mt-4 text-xs font-semibold text-slate-500">Services requested here are billed to {{ organization.name }}.</p>
                </template>

                <template v-else>
                    <a href="/provider/workspaces/billing/services" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to services</a>
                    <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm text-slate-500">Loading service details...</div>
                    <template v-else-if="selectedPlan">
                        <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                            <div class="flex items-start justify-between gap-10"><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Optional support service</p><h1 id="service-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ selectedPlan.name }}</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ selectedPlan.description }}</p></div><div class="shrink-0 border-l border-slate-200 pl-7 text-right"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">One-time fee</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ money(selectedPlan.amount, selectedPlan.currency) }}</p></div></div>
                        </header>

                        <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">What is included</h2><p class="mt-1 text-xs text-slate-500">Review the scope before opening secure checkout.</p></header>
                            <ul class="divide-y divide-slate-200"><li v-for="feature in selectedPlan.features" :key="feature" class="flex items-center gap-3 px-6 py-3.5 text-sm font-semibold text-slate-800"><i class="fa-solid fa-check text-emerald-600" aria-hidden="true"></i>{{ feature }}</li></ul>
                            <div v-if="selectedPlan.best_for" class="border-t border-slate-200 bg-slate-50 px-6 py-4 text-sm text-slate-600"><span class="font-bold text-slate-800">Best for:</span> {{ selectedPlan.best_for }}</div>
                        </section>

                        <section v-if="needsSupportScope" class="mt-4 border border-slate-200 bg-white shadow-sm">
                            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">Choose support areas</h2><p class="mt-1 text-xs text-slate-500">Select at least one area for this application cycle.</p></header>
                            <div class="grid grid-cols-3 gap-px bg-slate-200"><label v-for="area in selectedPlan.support_areas" :key="area.value" class="flex cursor-pointer items-start gap-3 bg-white px-5 py-4 hover:bg-slate-50"><input type="checkbox" :checked="form.support_areas.includes(area.value)" class="mt-0.5 h-4 w-4 accent-slate-950" @change="toggleSupportArea(area.value)"><span class="text-sm font-semibold leading-5 text-slate-800">{{ area.label }}</span></label></div>
                            <div class="border-t border-slate-200 px-6 py-5"><label for="support-note" class="text-sm font-bold text-slate-800">Short note <span class="font-normal text-slate-400">(optional)</span></label><textarea id="support-note" v-model="form.support_note" rows="3" maxlength="1000" class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm outline-none focus:border-slate-950" placeholder="Mention the main blocker your team is facing."></textarea></div>
                        </section>

                        <section class="mt-4 border border-slate-200 bg-white px-6 py-5 shadow-sm">
                            <label class="flex cursor-pointer items-start gap-3"><input v-model="form.accept_terms" type="checkbox" class="mt-0.5 h-4 w-4 accent-slate-950"><span class="text-sm leading-6 text-slate-700">I agree to this service scope and understand that work starts after payment is confirmed.</span></label>
                            <div class="mt-5 flex items-center justify-between border-t border-slate-200 pt-5"><div><p class="text-sm font-bold text-slate-900">Secure checkout through {{ gateway.name || 'PayMongo' }}</p><p class="mt-1 text-xs text-slate-500">{{ gateway.configured ? `Available methods: ${paymentMethods(gateway.payment_methods)}` : 'Online payment is temporarily unavailable.' }}</p></div><button type="button" :disabled="!canStartCheckout || isOpeningCheckout" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40" @click="startCheckout">{{ isOpeningCheckout ? 'Opening checkout...' : 'Continue to PayMongo' }} <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i></button></div>
                        </section>
                    </template>
                    <div v-else class="mt-4 border border-rose-200 bg-rose-50 px-6 py-8 text-center text-sm font-semibold text-rose-800">This optional service is not available.</div>
                </template>
            </div>
        </section>
    </main>
</template>
