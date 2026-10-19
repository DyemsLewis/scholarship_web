<script setup>
import { onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const planCode = window.location.pathname.match(/\/provider\/workspaces\/billing\/services\/([^/]+)$/)?.[1] ?? '';
const isLoading = ref(true);
const errorMessage = ref('');
const plan = ref(null);

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

async function loadService() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/workspaces/billing/services/${planCode}/data`);
        plan.value = response.data.plan ?? null;
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'This support service could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadService);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="service-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/billing/services" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to services
                </a>

                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm text-slate-500">Loading service details...</div>

                <div v-else-if="errorMessage || !plan" class="mt-4 border border-rose-200 bg-rose-50 px-6 py-8 text-center text-sm font-semibold text-rose-800">
                    {{ errorMessage || 'This support service is not available.' }}
                </div>

                <template v-else>
                    <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                        <div class="flex items-start justify-between gap-10 px-7 py-6">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">{{ plan.category }}</p>
                                <h1 id="service-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ plan.name }}</h1>
                                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ plan.description }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">One-time fee</p>
                                <p class="mt-1 text-2xl font-bold text-slate-950">{{ money(plan.amount, plan.currency) }}</p>
                                <a :href="`/provider/workspaces/billing/services/${plan.code}/request`" class="mt-3 inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                                    Start request
                                    <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 border-t border-slate-200 bg-slate-50">
                            <div class="px-6 py-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Best for</p>
                                <p class="mt-1 text-sm font-semibold leading-5 text-slate-800">{{ plan.best_for }}</p>
                            </div>
                            <div class="border-l border-slate-200 px-6 py-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Typical delivery</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">{{ plan.timeline }}</p>
                            </div>
                            <div class="border-l border-slate-200 px-6 py-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Format</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">{{ plan.format }}</p>
                            </div>
                        </div>
                    </header>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-6 py-5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">Expected outcome</p>
                            <p class="mt-2 max-w-4xl text-base font-semibold leading-7 text-slate-900">{{ plan.outcome }}</p>
                        </div>

                        <div class="border-b border-slate-200 px-6 py-5">
                            <h2 class="text-base font-bold text-slate-950">What you receive</h2>
                            <ul class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                                <li v-for="feature in plan.features" :key="feature" class="flex items-center gap-3 py-3 text-sm font-semibold text-slate-800">
                                    <i class="fa-solid fa-check text-emerald-600" aria-hidden="true"></i>
                                    {{ feature }}
                                </li>
                            </ul>
                        </div>

                        <div class="border-b border-slate-200 px-6 py-5">
                            <h2 class="text-base font-bold text-slate-950">How it works</h2>
                            <ol class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                                <li v-for="(step, index) in plan.process" :key="step.title" class="grid grid-cols-[2.5rem_14rem_minmax(0,1fr)] items-center gap-4 py-3">
                                    <span class="grid h-8 w-8 place-items-center bg-slate-950 text-xs font-bold text-amber-300">{{ index + 1 }}</span>
                                    <span class="text-sm font-bold text-slate-900">{{ step.title }}</span>
                                    <span class="text-sm text-slate-600">{{ step.description }}</span>
                                </li>
                            </ol>
                        </div>

                        <div class="px-6 py-5">
                            <h2 class="text-base font-bold text-slate-950">Before you request</h2>
                            <ul class="mt-3 space-y-2">
                                <li v-for="item in plan.preparation" :key="item" class="flex items-start gap-3 text-sm leading-6 text-slate-700">
                                    <i class="fa-solid fa-circle mt-2.5 text-[5px] text-amber-600" aria-hidden="true"></i>
                                    {{ item }}
                                </li>
                            </ul>
                            <p v-if="plan.scope_note" class="mt-4 border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-xs font-semibold leading-5 text-slate-700">{{ plan.scope_note }}</p>
                        </div>
                    </section>

                    <section class="mt-4 flex items-center justify-between border border-slate-200 border-t-2 border-t-slate-950 bg-white px-6 py-5 shadow-sm">
                        <div>
                            <h2 class="text-base font-bold text-slate-950">Ready to request support?</h2>
                            <p class="mt-1 text-sm text-slate-600">Complete a short service-specific form, then review everything before payment.</p>
                        </div>
                        <a :href="`/provider/workspaces/billing/services/${plan.code}/request`" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">
                            Start request
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
