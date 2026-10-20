<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const applicantId = window.location.pathname.match(/applicants\/(\d+)/)?.[1];
const loading = ref(true);
const saving = ref(false);
const photoSaving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const applicant = ref(null);
const decision = ref('approved');
const notes = ref('');
const gradingScale = ref('percentage');
const academicResult = ref('');
const photoReason = ref('');
const verificationStatus = computed(() => applicant.value?.verification_oversight?.status || applicant.value?.applicant_verification_status || 'pending');

async function loadRecord() {
    loading.value = true;
    try {
        const response = await window.axios.get(`/admin/applicants/${applicantId}/review/data`);
        applicant.value = response.data.applicant;
        decision.value = verificationStatus.value === 'pending' ? 'approved' : verificationStatus.value;
        notes.value = applicant.value.applicant_verification_notes ?? '';
        gradingScale.value = applicant.value.grading_scale || 'percentage';
        academicResult.value = applicant.value.gwa ?? '';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The applicant record could not be loaded.';
    } finally { loading.value = false; }
}

async function saveDecision() {
    saving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const numericScale = ['percentage', 'grade_point'].includes(gradingScale.value);
        const response = await window.axios.patch(`/admin/users/${applicantId}/profile-verification`, {
            verification_status: decision.value,
            verification_notes: notes.value.trim() || null,
            academic_grading_scale: gradingScale.value,
            academic_result: numericScale && academicResult.value !== '' ? Number(academicResult.value) : null,
        });
        applicant.value = response.data.applicant;
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? 'The decision could not be saved.';
    } finally { saving.value = false; }
}

async function reviewPhoto(action) {
    photoSaving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/applicants/${applicantId}/profile-photo-review`, { action, reason: action === 'request_replacement' ? photoReason.value.trim() : null });
        applicant.value.profile_photo_review_status = response.data.photo_review_status;
        applicant.value.profile_photo_review_note = response.data.reason;
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? 'The photo review could not be saved.';
    } finally { photoSaving.value = false; }
}

function documentLabel(type) { return String(type).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer">
        <a href="/admin/workspaces/reviews/applicants" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs"></i>Applicant queue</a>
        <AdminWorkspaceHeader eyebrow="Applicant verification" title="Academic profile record" description="Compare the saved profile with supporting evidence." icon="fa-solid fa-user-check" />
        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading applicant record...</div>
        <p v-else-if="errorMessage && !applicant" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>
        <template v-else-if="applicant">
            <p v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ successMessage }}</p><p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>
            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5"><span class="relative grid h-20 w-20 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user-graduate text-2xl"></i><img v-if="applicant.profile_photo_url" :src="applicant.profile_photo_url" alt="Applicant photo" class="absolute inset-0 h-full w-full object-cover"></span><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Applicant record</p><h2 class="mt-1 truncate text-xl font-black text-slate-950">{{ applicant.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ applicant.email }}</p></div><span class="bg-slate-100 px-3 py-1.5 text-xs font-bold uppercase text-slate-700">{{ verificationStatus }}</span></div>
                <dl class="grid grid-cols-4 divide-x divide-slate-200"><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">School</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.school || 'Not provided' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Education</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.education_level || 'Not provided' }}<span v-if="applicant.year_level"> - {{ applicant.year_level }}</span></dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Course / strand</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.course_or_strand || 'Not provided' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Academic result</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.gwa ?? 'Not provided' }} <span v-if="applicant.grading_scale" class="text-xs font-semibold text-slate-500">({{ applicant.grading_scale.replaceAll('_', ' ') }})</span></dd></div></dl>
            </section>

            <section class="admin-panel mt-4 overflow-hidden"><header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Supporting evidence</h2><p class="mt-0.5 text-sm text-slate-500">Open the record that supports each saved claim.</p></div></header><div v-if="applicant.verification_documents?.length" class="staff-table-viewport"><table class="portal-data-table min-w-[58rem]"><thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="px-6 py-3">Evidence</th><th class="px-4 py-3">Review state</th><th class="px-4 py-3">Extracted result</th><th class="px-6 py-3 text-right">Preview</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="document in applicant.verification_documents" :key="document.id"><td class="px-6 py-4"><p class="text-sm font-bold text-slate-950">{{ documentLabel(document.document_type) }}</p><p class="mt-0.5 text-xs text-slate-500" data-file-name>{{ document.original_name }}</p></td><td class="px-4 py-4 text-sm font-semibold capitalize text-slate-700">{{ document.status }}</td><td class="px-4 py-4 text-sm text-slate-600">{{ document.ocr_grade ?? 'Not extracted' }}</td><td class="px-6 py-4 text-right"><a :href="document.view_url" data-file-preview="true" :data-file-name="document.original_name" class="inline-grid h-9 w-9 place-items-center border border-slate-300 text-slate-700 hover:bg-slate-950 hover:text-white" aria-label="Preview evidence" title="Preview evidence"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a></td></tr></tbody></table></div><p v-else class="px-6 py-10 text-center text-sm font-semibold text-slate-500">No supporting evidence has been uploaded.</p></section>

            <section class="admin-panel mt-4 p-6"><div class="flex items-start justify-between gap-6"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-image-portrait" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Applicant photo</h2><p class="mt-0.5 text-sm text-slate-500">Check that the image clearly identifies the applicant.</p></div></div><span class="bg-slate-100 px-2.5 py-1.5 text-xs font-bold uppercase text-slate-700">{{ applicant.profile_photo_review_status || 'Not checked' }}</span></div><div class="mt-4 flex items-end gap-3"><label class="min-w-0 flex-1"><span class="mb-1.5 block text-sm font-bold text-slate-700">Replacement reason</span><input v-model="photoReason" type="text" maxlength="1000" class="h-11 w-full border border-slate-300 px-3" placeholder="Required only when requesting a replacement"></label><button type="button" :disabled="photoSaving || !photoReason.trim()" class="h-11 border border-slate-300 bg-white px-4 text-sm font-bold disabled:opacity-40" @click="reviewPhoto('request_replacement')">Request replacement</button><button type="button" :disabled="photoSaving" class="h-11 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40" @click="reviewPhoto('approve')">Approve photo</button></div></section>

            <form class="admin-panel mt-4 p-6" @submit.prevent="saveDecision"><div class="flex items-start justify-between gap-8"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Decision</p><h2 class="mt-0.5 text-lg font-black text-slate-950">Record profile verification</h2><p class="mt-0.5 text-sm text-slate-500">Use the values shown in the academic record.</p></div></div><button type="submit" :disabled="saving" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save decision' }}</button></div><div class="mt-5 grid grid-cols-3 gap-4"><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="approved">Verify profile</option><option value="rejected">Request replacement</option><option value="pending">Reopen review</option></select></label><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Grading scale</span><select v-model="gradingScale" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="percentage">Percentage</option><option value="grade_point">Grade point</option><option value="pass_fail">Pass / fail</option><option value="other">Other</option></select></label><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Verified result</span><input v-model="academicResult" type="number" step="0.01" class="h-11 w-full border border-slate-300 px-3" :disabled="!['percentage', 'grade_point'].includes(gradingScale)"></label></div><label class="mt-4 block"><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="decision !== 'approved'" class="text-rose-700">required</span></span><textarea v-model="notes" rows="3" maxlength="1500" class="w-full border border-slate-300 px-3 py-2" placeholder="State what needs correction or why the review was reopened."></textarea></label></form>
        </template>
    </AdminWorkspacePage>
</template>
