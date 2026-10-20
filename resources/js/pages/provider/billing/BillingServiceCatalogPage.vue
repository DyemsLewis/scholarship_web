<script setup>
import { onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const organization = ref(null);
const plans = ref([]);

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

async function loadCatalog() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/billing/services/data');
        organization.value = response.data.organization ?? null;
        plans.value = response.data.plans ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Support services could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadCatalog);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="services-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Platform assistance</p><h1 id="services-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Optional support services</h1><p class="mt-1 text-sm leading-6 text-slate-600">Choose focused help for work your team wants the platform to support.</p></div></div>
                        <a href="/provider/workspaces/billing" class="inline-flex h-11 shrink-0 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 hover:text-slate-950">
                            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                            Request desk
                        </a>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Available support services">
                    <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                        <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Choose a service</h2><p class="mt-1 text-xs text-slate-500">Core scholarship tools remain available without purchasing these services.</p></div></div>
                        <p v-if="organization" class="text-xs font-semibold text-slate-500">For {{ organization.name }}</p>
                    </div>

                    <div v-if="isLoading" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading support services...</p></div>

                    <div v-else-if="!plans.length" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span><p class="mt-3 text-sm font-bold text-slate-800">No optional services are available.</p></div>
                    <div v-else class="divide-y divide-slate-200">
                        <article v-for="plan in plans" :key="plan.code" class="grid grid-cols-[minmax(0,1fr)_12rem_11rem] items-center gap-7 px-6 py-6 hover:bg-slate-50">
                            <div class="min-w-0">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300">
                                        <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ plan.category }}</p>
                                        <h2 class="mt-0.5 text-lg font-bold text-slate-950">{{ plan.name }}</h2>
                                    </div>
                                </div>
                                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ plan.description }}</p>
                                <p v-if="plan.best_for" class="mt-2 text-xs font-semibold text-slate-500">Best for: {{ plan.best_for }}</p>
                            </div>

                            <div class="border-l border-slate-200 pl-6">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Typical delivery</p>
                                <p class="mt-1 text-sm font-bold text-slate-900">{{ plan.timeline }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ plan.format }}</p>
                            </div>

                            <div class="border-l border-slate-200 pl-6">
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">One-time fee</p>
                                <p class="mt-1 text-lg font-bold text-slate-950">{{ money(plan.amount, plan.currency) }}</p>
                                <a :href="plan.details_url" class="mt-3 inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                                    View service
                                    <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                </a>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </section>
    </main>
</template>
