<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const programId = window.location.pathname.match(/programs\/(\d+)/)?.[1];
const loading = ref(true);
const saving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const program = ref(null);
const decision = ref('published');
const notes = ref('');
const statusClass = computed(() => program.value?.status === 'published' ? 'bg-emerald-100 text-emerald-800' : program.value?.status === 'rejected' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700');

async function loadRecord() {
    loading.value = true;
    try {
        const response = await window.axios.get(`/admin/scholarships/${programId}/review/data`);
        program.value = response.data.scholarship;
        decision.value = program.value.status === 'pending_review' ? 'published' : program.value.status;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The program record could not be loaded.';
    } finally { loading.value = false; }
}

async function saveDecision() {
    saving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/scholarships/${programId}/review`, { status: decision.value, review_notes: notes.value.trim() || null });
        program.value = response.data.scholarship;
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? 'The decision could not be saved.';
    } finally { saving.value = false; }
}

onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer">
        <a href="/admin/workspaces/reviews/programs" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs"></i>Program queue</a>
        <AdminWorkspaceHeader eyebrow="Program review" :title="program?.title || 'Program record'" description="Check publication details and applicant-facing terms." icon="fa-solid fa-graduation-cap" />
        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading program record...</div>
        <p v-else-if="errorMessage && !program" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>
        <template v-else-if="program">
            <p v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ successMessage }}</p><p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>
            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5"><img :src="program.image_url" alt="" class="h-16 w-16 border border-slate-200 bg-white object-contain p-1"><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Scholarship program</p><h2 class="mt-1 truncate text-xl font-black text-slate-950">{{ program.title }}</h2><p class="mt-1 text-sm text-slate-500">{{ program.provider }}</p></div><span :class="['px-3 py-1.5 text-xs font-bold uppercase', statusClass]">{{ program.status.replaceAll('_', ' ') }}</span></div>
                <dl class="grid grid-cols-4 divide-x divide-slate-200"><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Category</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.category || 'Not specified' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Application period</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.application_opens_at || 'Not set' }} to {{ program.deadline || 'Not set' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Available slots</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.slots_available || 'Not limited' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Minimum grade</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.minimum_grade_label || 'Not required' }}</dd></div></dl>
            </section>
            <section class="admin-panel mt-4 divide-y divide-slate-200">
                <div class="px-6 py-5"><h2 class="font-black text-slate-950">Program summary</h2><p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ program.description || 'No description provided.' }}</p></div>
                <div class="px-6 py-5"><h2 class="font-black text-slate-950">Eligibility and required files</h2><p class="mt-2 text-sm leading-6 text-slate-600">{{ program.eligibility || 'No general eligibility note.' }}</p><div class="mt-3 flex flex-wrap gap-2"><span v-for="requirement in program.requirements || []" :key="requirement" class="border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700">{{ requirement }}</span><span v-if="!program.requirements?.length" class="text-sm text-slate-500">No required files listed.</span></div></div>
                <details class="group px-6 py-5"><summary class="cursor-pointer list-none font-black text-slate-950">Applicant terms and support <i class="fa-solid fa-chevron-down ml-2 text-xs text-slate-400 group-open:rotate-180"></i></summary><div class="mt-3 grid grid-cols-3 gap-6 text-sm"><div><p class="font-bold text-slate-700">Benefits</p><p class="mt-1 text-slate-600">{{ program.benefit_summary || 'Not specified' }}</p></div><div><p class="font-bold text-slate-700">Recipient agreement</p><p class="mt-1 line-clamp-4 whitespace-pre-line text-slate-600">{{ program.recipient_agreement || 'No additional agreement.' }}</p></div><div><p class="font-bold text-slate-700">Provider handoff</p><p class="mt-1 line-clamp-4 whitespace-pre-line text-slate-600">{{ program.handoff_instructions || 'No external handoff.' }}</p></div></div></details>
            </section>
            <form class="admin-panel mt-4 p-6" @submit.prevent="saveDecision"><div class="flex items-start justify-between gap-8"><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Decision</p><h2 class="mt-1 text-lg font-black text-slate-950">Record publication decision</h2><p class="mt-1 text-sm text-slate-500">Publishing makes the program visible to applicants.</p></div><button type="submit" :disabled="saving" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save decision' }}</button></div><div class="mt-5 grid grid-cols-[14rem_minmax(0,1fr)] gap-4"><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="published">Publish</option><option value="rejected">Return to provider</option><option value="pending_review">Keep in review</option></select></label><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="decision === 'rejected'" class="text-rose-700">required</span></span><textarea v-model="notes" rows="3" maxlength="1500" class="w-full border border-slate-300 px-3 py-2" placeholder="State what must be corrected."></textarea></label></div></form>
        </template>
    </AdminWorkspacePage>
</template>
