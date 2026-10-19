<script setup>
import { onMounted, reactive, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const planCode = window.location.pathname.match(/\/provider\/workspaces\/billing\/services\/([^/]+)\/request$/)?.[1] ?? '';
const requestId = new URLSearchParams(window.location.search).get('request');
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const errors = ref({});
const plan = ref(null);
const organization = ref(null);
const programs = ref([]);
const form = reactive({
    scholarship_id: '',
    contact_name: '',
    contact_email: '',
});

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format((amount || 0) / 100);
}

function fieldError(key) {
    const message = errors.value?.[key];
    return Array.isArray(message) ? message[0] : message;
}

function toggleChoice(key, value) {
    const selected = Array.isArray(form[key]) ? form[key] : [];
    form[key] = selected.includes(value)
        ? selected.filter((item) => item !== value)
        : [...selected, value];
}

function initializeForm(data) {
    const savedRequest = data.request;
    const defaults = data.defaults ?? {};

    Object.assign(form, defaults, savedRequest?.answers ?? {});
    form.scholarship_id = savedRequest?.scholarship_id ?? '';

    for (const field of data.plan?.intake?.fields ?? []) {
        if (field.type === 'checkboxes' && !Array.isArray(form[field.key])) {
            form[field.key] = [];
        } else if (!(field.key in form)) {
            form[field.key] = '';
        }
    }
}

async function loadIntake() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/workspaces/billing/services/${planCode}/request/data`, {
            params: requestId ? { request: requestId } : {},
        });
        plan.value = response.data.plan ?? null;
        organization.value = response.data.organization ?? null;
        programs.value = response.data.programs ?? [];
        initializeForm(response.data);

        if (response.data.request && !response.data.request.editable) {
            window.location.assign(response.data.request.review_url);
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The service request form could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function continueToReview() {
    isSaving.value = true;
    errorMessage.value = '';
    errors.value = {};

    try {
        const payload = {
            ...form,
            scholarship_id: form.scholarship_id || null,
        };
        const response = requestId
            ? await window.axios.patch(`/provider/workspaces/billing/service-requests/${requestId}`, payload)
            : await window.axios.post(`/provider/workspaces/billing/services/${planCode}/requests`, payload);

        window.location.assign(response.data.review_url);
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message || 'Check the request details and try again.';
        isSaving.value = false;
    }
}

onMounted(loadIntake);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="intake-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="`/provider/workspaces/billing/services/${planCode}`" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to service
                </a>

                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm text-slate-500">Loading request form...</div>

                <div v-else-if="!plan" class="mt-4 border border-rose-200 bg-rose-50 px-6 py-8 text-center text-sm font-semibold text-rose-800">
                    {{ errorMessage || 'This request form is not available.' }}
                </div>

                <template v-else>
                    <div class="mt-4 grid grid-cols-3 border border-slate-200 bg-white shadow-sm" aria-label="Request progress">
                        <div class="flex items-center gap-3 border-r border-slate-200 bg-amber-50 px-5 py-4">
                            <span class="grid h-8 w-8 place-items-center bg-amber-400 text-xs font-bold text-slate-950">1</span>
                            <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Current step</p><p class="text-sm font-bold text-slate-950">Request details</p></div>
                        </div>
                        <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-4 text-slate-500">
                            <span class="grid h-8 w-8 place-items-center bg-slate-100 text-xs font-bold">2</span>
                            <p class="text-sm font-bold">Review</p>
                        </div>
                        <div class="flex items-center gap-3 px-5 py-4 text-slate-500">
                            <span class="grid h-8 w-8 place-items-center bg-slate-100 text-xs font-bold">3</span>
                            <p class="text-sm font-bold">Payment</p>
                        </div>
                    </div>

                    <header class="mt-4 flex items-start justify-between gap-8 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Service request</p>
                            <h1 id="intake-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ plan.name }}</h1>
                            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ plan.intake.description }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">One-time fee</p>
                            <p class="mt-1 text-xl font-bold text-slate-950">{{ money(plan.amount, plan.currency) }}</p>
                            <p class="mt-1 text-xs text-slate-500">No payment yet</p>
                        </div>
                    </header>

                    <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                    <form class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="continueToReview">
                        <header class="border-b border-slate-200 px-6 py-5">
                            <h2 class="text-lg font-bold text-slate-950">{{ plan.intake.title }}</h2>
                            <p class="mt-1 text-sm text-slate-600">Only information needed to prepare this service is requested.</p>
                        </header>

                        <div class="grid grid-cols-2 gap-x-6 gap-y-5 px-6 py-6">
                            <div v-for="field in plan.intake.fields" :key="field.key" :class="field.type === 'textarea' || field.type === 'checkboxes' || field.type === 'program' ? 'col-span-2' : ''">
                                <label :for="`service-${field.key}`" class="text-sm font-bold text-slate-800">
                                    {{ field.label }}
                                    <span v-if="!field.required" class="font-normal text-slate-400">(optional)</span>
                                </label>

                                <template v-if="field.type === 'program'">
                                    <select :id="`service-${field.key}`" v-model="form[field.key]" :class="['mt-2 h-12 w-full border bg-white px-4 text-sm outline-none', fieldError(field.key) ? 'border-rose-400' : 'border-slate-300 focus:border-slate-950']">
                                        <option value="">{{ field.required ? 'Choose a program' : 'No specific program' }}</option>
                                        <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }} · {{ program.status }}</option>
                                    </select>
                                    <p v-if="field.required && !programs.length" class="mt-2 text-xs font-semibold text-amber-700">No program is available yet. Ask your program coordinator to create one before requesting this service.</p>
                                </template>

                                <select v-else-if="field.type === 'select'" :id="`service-${field.key}`" v-model="form[field.key]" :class="['mt-2 h-12 w-full border bg-white px-4 text-sm outline-none', fieldError(field.key) ? 'border-rose-400' : 'border-slate-300 focus:border-slate-950']">
                                    <option value="">Choose one</option>
                                    <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>

                                <textarea v-else-if="field.type === 'textarea'" :id="`service-${field.key}`" v-model="form[field.key]" rows="4" maxlength="2000" :placeholder="field.placeholder" :class="['mt-2 w-full border px-4 py-3 text-sm leading-6 outline-none', fieldError(field.key) ? 'border-rose-400' : 'border-slate-300 focus:border-slate-950']"></textarea>

                                <div v-else-if="field.type === 'checkboxes'" class="mt-2 divide-y divide-slate-200 border-y border-slate-200">
                                    <label v-for="option in field.options" :key="option.value" class="flex cursor-pointer items-center gap-3 py-3 hover:bg-slate-50">
                                        <input type="checkbox" :checked="form[field.key]?.includes(option.value)" class="h-4 w-4 accent-slate-950" @change="toggleChoice(field.key, option.value)">
                                        <span class="text-sm font-semibold text-slate-800">{{ option.label }}</span>
                                    </label>
                                </div>

                                <input v-else :id="`service-${field.key}`" v-model="form[field.key]" :type="field.type" :placeholder="field.placeholder" :class="['mt-2 h-12 w-full border px-4 text-sm outline-none', fieldError(field.key) ? 'border-rose-400' : 'border-slate-300 focus:border-slate-950']">

                                <p v-if="fieldError(field.key)" class="mt-1 text-xs font-semibold text-rose-700">{{ fieldError(field.key) }}</p>
                            </div>
                        </div>

                        <div class="border-t border-slate-200 px-6 py-5">
                            <h2 class="text-base font-bold text-slate-950">Service contact</h2>
                            <p class="mt-1 text-xs text-slate-500">The platform team will use this contact for questions about the request.</p>
                            <div class="mt-4 grid grid-cols-2 gap-6">
                                <div>
                                    <label for="service-contact-name" class="text-sm font-bold text-slate-800">Contact person</label>
                                    <input id="service-contact-name" v-model="form.contact_name" type="text" maxlength="120" :class="['mt-2 h-12 w-full border px-4 text-sm outline-none', fieldError('contact_name') ? 'border-rose-400' : 'border-slate-300 focus:border-slate-950']">
                                    <p v-if="fieldError('contact_name')" class="mt-1 text-xs font-semibold text-rose-700">{{ fieldError('contact_name') }}</p>
                                </div>
                                <div>
                                    <label for="service-contact-email" class="text-sm font-bold text-slate-800">Contact email</label>
                                    <input id="service-contact-email" v-model="form.contact_email" type="email" maxlength="160" :class="['mt-2 h-12 w-full border px-4 text-sm outline-none', fieldError('contact_email') ? 'border-rose-400' : 'border-slate-300 focus:border-slate-950']">
                                    <p v-if="fieldError('contact_email')" class="mt-1 text-xs font-semibold text-rose-700">{{ fieldError('contact_email') }}</p>
                                </div>
                            </div>
                        </div>

                        <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-5">
                            <p class="text-xs font-semibold text-slate-500">You will review these details before payment.</p>
                            <button type="submit" :disabled="isSaving" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                                {{ isSaving ? 'Saving...' : 'Continue to review' }}
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </button>
                        </footer>
                    </form>

                    <p v-if="organization" class="mt-4 text-xs font-semibold text-slate-500">Request prepared for {{ organization.name }}.</p>
                </template>
            </div>
        </section>
    </main>
</template>
