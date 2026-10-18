<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../../../components/ConfirmationDialog.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const programs = ref([]);
const candidates = ref([]);
const selectedProgram = ref(new URLSearchParams(window.location.search).get('program_id') ?? '');
const selectedRecipients = ref([]);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const confirmation = ref(false);
let reloadTimer = null;
const form = ref({
    title: '',
    release_at: tomorrowMorning(),
    benefit_description: '',
    amount: '',
    release_method: 'in_person',
    location: '',
    instructions: '',
    requires_original_verification: false,
});

const eligibleCandidates = computed(() => candidates.value.filter((candidate) => candidate.eligible));
const blockedCandidates = computed(() => candidates.value.filter((candidate) => !candidate.eligible));
const allEligibleSelected = computed(() => eligibleCandidates.value.length > 0 && eligibleCandidates.value.every((candidate) => selectedRecipients.value.includes(candidate.application_id)));

function tomorrowMorning() {
    const date = new Date();
    date.setDate(date.getDate() + 1);
    date.setHours(9, 0, 0, 0);
    const offset = date.getTimezoneOffset() * 60 * 1000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return errors ? Object.values(errors).flat()[0] : (error.response?.data?.message || fallback);
}

function toggleAllEligible() {
    selectedRecipients.value = allEligibleSelected.value
        ? []
        : eligibleCandidates.value.map((candidate) => candidate.application_id);
}

async function loadScheduleData(initial = false) {
    if (initial) isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/releases/schedule/data', {
            params: {
                program_id: selectedProgram.value || undefined,
                release_at: form.value.release_at || undefined,
            },
        });
        programs.value = response.data.programs ?? [];
        if (!selectedProgram.value && response.data.selected_program) selectedProgram.value = String(response.data.selected_program.id);
        candidates.value = response.data.candidates ?? [];
        const eligibleIds = new Set(eligibleCandidates.value.map((candidate) => candidate.application_id));
        selectedRecipients.value = selectedRecipients.value.filter((id) => eligibleIds.has(id));
    } catch (error) {
        errorMessage.value = firstError(error, 'Release eligibility could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

watch(selectedProgram, () => {
    selectedRecipients.value = [];
    loadScheduleData();
});
watch(() => form.value.release_at, () => {
    window.clearTimeout(reloadTimer);
    reloadTimer = window.setTimeout(() => loadScheduleData(), 350);
});

async function saveRelease() {
    confirmation.value = false;
    isSaving.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post(`/provider/scholarships/${selectedProgram.value}/benefit-releases`, {
            ...form.value,
            amount: form.value.amount === '' ? null : Number(form.value.amount),
            requires_original_verification: Boolean(form.value.requires_original_verification),
            recipient_ids: selectedRecipients.value,
        });
        window.location.assign(`/provider/workspaces/releases/distributions/${response.data.release.id}`);
    } catch (error) {
        errorMessage.value = firstError(error, 'The benefit release could not be scheduled.');
    } finally {
        isSaving.value = false;
    }
}

onMounted(() => loadScheduleData(true));
onBeforeUnmount(() => window.clearTimeout(reloadTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="schedule-release-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/releases/upcoming" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to releases</a>
                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>

                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Benefit distribution</p>
                    <h1 id="schedule-release-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">Schedule a benefit release</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Set the distribution details, then choose recipients who are ready.</p>
                </header>

                <form class="mt-4" @submit.prevent="confirmation = true">
                    <section class="border border-slate-200 bg-white shadow-sm">
                        <header class="border-b border-slate-200 px-6 py-5"><h2 class="text-xl font-bold text-slate-950">Release details</h2><p class="mt-1 text-sm text-slate-500">These details will appear in each selected recipient’s monitoring record.</p></header>
                        <div class="grid grid-cols-3 gap-5 px-6 py-6">
                            <label class="text-sm font-semibold text-slate-700">Program<select v-model="selectedProgram" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="" disabled>Select a program</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                            <label class="text-sm font-semibold text-slate-700">Release title<input v-model="form.title" required maxlength="120" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="Example: First semester allowance"></label>
                            <label class="text-sm font-semibold text-slate-700">Release date and time<input v-model="form.release_at" type="datetime-local" required class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label>
                            <label class="col-span-2 text-sm font-semibold text-slate-700">Benefit description<input v-model="form.benefit_description" required maxlength="255" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="What recipients will receive"></label>
                            <label class="text-sm font-semibold text-slate-700">Amount <span class="font-normal text-slate-400">(optional)</span><input v-model="form.amount" type="number" min="0" step="0.01" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="PHP"></label>
                            <label class="text-sm font-semibold text-slate-700">Release method<select v-model="form.release_method" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="in_person">In person</option><option value="bank_transfer">Bank transfer</option><option value="e_wallet">E-wallet</option><option value="other">Other arrangement</option></select></label>
                            <label v-if="form.release_method === 'in_person'" class="col-span-2 text-sm font-semibold text-slate-700">Location<input v-model="form.location" required maxlength="255" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="Distribution venue"></label>
                            <label v-else class="col-span-2 text-sm font-semibold text-slate-700">Instructions <span class="font-normal text-slate-400">(optional)</span><input v-model="form.instructions" maxlength="2000" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="Transfer or collection instructions"></label>
                        </div>
                        <label class="flex items-start gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 text-sm font-semibold text-slate-700"><input v-model="form.requires_original_verification" type="checkbox" class="mt-0.5 h-4 w-4">Require original-document verification before a release can be marked received.</label>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-5"><div><h2 class="text-xl font-bold text-slate-950">Eligible recipients</h2><p class="mt-1 text-sm text-slate-500">Eligibility uses monitoring requirements due before the selected release date.</p></div><button type="button" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="!eligibleCandidates.length" @click="toggleAllEligible">{{ allEligibleSelected ? 'Clear selection' : 'Select all eligible' }}</button></header>
                        <div v-if="isLoading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Checking recipient eligibility...</div>
                        <div v-else-if="!candidates.length" class="px-6 py-14 text-center text-sm text-slate-500">No accepted recipients are available for this program.</div>
                        <div v-else class="divide-y divide-slate-200">
                            <label v-for="candidate in candidates" :key="candidate.application_id" :class="['flex items-center gap-4 px-6 py-4', candidate.eligible ? 'cursor-pointer hover:bg-slate-50' : 'bg-slate-50']">
                                <input v-model="selectedRecipients" type="checkbox" :value="candidate.application_id" :disabled="!candidate.eligible" class="h-4 w-4 shrink-0">
                                <img v-if="candidate.profile_photo_url" :src="candidate.profile_photo_url" :alt="`${candidate.name} profile`" class="h-11 w-11 shrink-0 border border-slate-200 object-cover"><span v-else class="grid h-11 w-11 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-950">{{ candidate.name }}</span><span class="mt-1 block truncate text-xs text-slate-500">{{ candidate.email }}</span></span>
                                <span class="w-[30rem] text-right"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', candidate.eligible ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800']">{{ candidate.eligibility_label }}</span><span class="mt-2 block text-xs leading-5 text-slate-500">{{ candidate.eligibility_reason }}</span></span>
                            </label>
                        </div>
                        <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="text-sm font-semibold text-slate-600">{{ selectedRecipients.length }} selected · {{ blockedCandidates.length }} need monitoring action</p><button type="submit" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40" :disabled="isSaving || !selectedProgram || !selectedRecipients.length">Review and schedule</button></footer>
                    </section>
                </form>
            </div>
        </section>

        <ConfirmationDialog :open="confirmation" title="Schedule benefit release" :message="`Publish this distribution to ${selectedRecipients.length} selected recipient${selectedRecipients.length === 1 ? '' : 's'}?`" confirm-label="Schedule release" tone="warning" @confirm="saveRelease" @cancel="confirmation = false" />
    </main>
</template>
