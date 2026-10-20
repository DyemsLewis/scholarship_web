<script setup>
import { onMounted, reactive, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const errors = ref({});
const form = reactive({
    provider_contact_email: '',
    provider_contact_number: '',
    provider_contact_department: '',
    provider_office_hours: '',
    provider_address: '',
    provider_service_area: '',
});
const fieldClass = 'mt-2 h-11 w-full border border-slate-300 bg-white px-3.5 text-sm text-slate-900 outline-none focus:border-slate-950';
const labelClass = 'text-sm font-bold text-slate-800';

function firstError(field) {
    return errors.value?.[field]?.[0] ?? '';
}

function applyValues(values = {}) {
    for (const key of Object.keys(form)) form[key] = values[key] ?? '';
}

async function loadProfile() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/organization-profile/data');
        applyValues(response.data.profile?.values);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The organization contact details could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function saveContact() {
    isSaving.value = true;
    errors.value = {};
    errorMessage.value = '';
    try {
        await window.axios.patch('/provider/workspaces/organization-profile/contact', form);
        await loadProfile();
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message || 'The organization contact details could not be saved.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadProfile);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="organization-contact-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-address-book" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Organization profile</p><h1 id="organization-contact-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Contact and location</h1><p class="mt-1 text-sm leading-6 text-slate-600">Maintain the public contact point and the area served by the organization.</p></div></div>
                        <button type="submit" form="organization-contact-form" :disabled="isLoading || isSaving" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"><i :class="['fa-solid text-amber-300', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : 'Save details' }}</button>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading contact and location...</p></div>

                <form v-else id="organization-contact-form" class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="saveContact">
                    <section class="border-b border-slate-200 px-6 py-6" aria-labelledby="applicant-contact-title">
                        <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-address-book" aria-hidden="true"></i></span><div><h2 id="applicant-contact-title" class="text-lg font-bold text-slate-950">Applicant contact</h2><p class="mt-1 text-sm text-slate-500">These details appear when applicants need clarification from the provider.</p></div></div>
                        <div class="mt-6 grid grid-cols-2 gap-5">
                            <label><span :class="labelClass">Public email</span><input v-model="form.provider_contact_email" type="email" :class="fieldClass"><span v-if="firstError('provider_contact_email')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('provider_contact_email') }}</span></label>
                            <label><span :class="labelClass">Public phone</span><input v-model="form.provider_contact_number" type="tel" :class="fieldClass" placeholder="09XX XXX XXXX"><span v-if="firstError('provider_contact_number')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('provider_contact_number') }}</span></label>
                            <label><span :class="labelClass">Contact department <span class="font-normal text-slate-400">(optional)</span></span><input v-model="form.provider_contact_department" type="text" :class="fieldClass" placeholder="Scholarship office"></label>
                            <label><span :class="labelClass">Office hours <span class="font-normal text-slate-400">(optional)</span></span><input v-model="form.provider_office_hours" type="text" :class="fieldClass" placeholder="Monday-Friday, 8:00 AM-5:00 PM"></label>
                        </div>
                    </section>

                    <section class="px-6 py-6" aria-labelledby="organization-location-title">
                        <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span><div><h2 id="organization-location-title" class="text-lg font-bold text-slate-950">Location and reach</h2><p class="mt-1 text-sm text-slate-500">Use an office address and a simple description of the communities served.</p></div></div>
                        <label class="mt-6 block"><span :class="labelClass">Office address</span><textarea v-model="form.provider_address" rows="3" class="mt-2 w-full border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none focus:border-slate-950" placeholder="Building, street, city or municipality, province"></textarea><span v-if="firstError('provider_address')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('provider_address') }}</span></label>
                        <label class="mt-5 block"><span :class="labelClass">Service area</span><textarea v-model="form.provider_service_area" rows="3" class="mt-2 w-full border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 text-slate-900 outline-none focus:border-slate-950" placeholder="Example: Students residing in Rizal Province"></textarea><span v-if="firstError('provider_service_area')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('provider_service_area') }}</span></label>
                    </section>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="text-xs font-semibold text-slate-500">Changing a verified office address sends the profile back for administrator review.</p><button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : 'Save contact and location' }}</button></footer>
                </form>
            </div>
        </section>
    </main>
</template>
