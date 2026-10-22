<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import ApplicantApplicationShell from '../components/application-detail/ApplicantApplicationShell.vue';
import ApplicationCorrectionDialog from '../components/application-detail/ApplicationCorrectionDialog.vue';
import ApplicationDocumentList from '../components/application-detail/ApplicationDocumentList.vue';
import ApplicationSubmissionSnapshot from '../components/application-detail/ApplicationSubmissionSnapshot.vue';
import { showPortalToast } from '../support/portalToast';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');
const documentTermsAccepted = ref(false);
const fileInput = ref(null);
const uploadRequirement = ref('');
const previewDocument = ref(null);
const showCorrectionDialog = ref(false);
const correctionResponse = ref('');
const isSendingCorrection = ref(false);

const submission = computed(() => detail.value?.content ?? {});
const correction = computed(() => submission.value.correction ?? {});
const canRespondToCorrection = computed(() => Boolean(detail.value?.permissions?.can_respond_to_correction));
const orderedDocuments = computed(() => [...(submission.value.documents ?? [])].sort((left, right) => {
    if (Boolean(left.needs_attention) !== Boolean(right.needs_attention)) {
        return left.needs_attention ? -1 : 1;
    }

    if (Boolean(left.required) !== Boolean(right.required)) {
        return left.required ? -1 : 1;
    }

    return left.requirement.localeCompare(right.requirement);
}));
const shellDetail = computed(() => {
    if (!detail.value) {
        return null;
    }

    const sourceDecision = detail.value.decision ?? {};
    const decision = {
        ...sourceDecision,
        action: sourceDecision.action ? { ...sourceDecision.action } : null,
    };

    if (decision.action?.target === 'correction') {
        decision.action.href = '#correction-request';
        decision.action.label = 'Respond to request';
    } else if (submission.value.documents?.some((document) => document.needs_attention)) {
        decision.action = {
            kind: 'link',
            label: 'Review files',
            href: '#application-files',
        };
    }

    return { ...detail.value, decision };
});

function firstValidationError(error, fallback) {
    const errors = error.response?.data?.errors;

    if (errors && typeof errors === 'object') {
        return Object.values(errors).flat().find(Boolean) ?? fallback;
    }

    return error.response?.data?.message ?? fallback;
}

async function loadSubmission() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`, {
            params: { view: 'submission' },
        });
        detail.value = response.data.detail;

        if (new URLSearchParams(window.location.search).get('action') === 'correction'
            && detail.value.permissions.can_respond_to_correction) {
            showCorrectionDialog.value = true;
        }
    } catch (error) {
        errorMessage.value = firstValidationError(error, 'We could not load the submitted application record.');
    } finally {
        isLoading.value = false;
    }
}

function requestUpload(requirement) {
    if (!documentTermsAccepted.value) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Accept the document upload terms before choosing a file.',
        });
        return;
    }

    uploadRequirement.value = requirement;
    if (fileInput.value) {
        fileInput.value.value = '';
        fileInput.value.click();
    }
}

async function uploadDocument(event) {
    const file = event.target.files?.[0];

    if (!file || !uploadRequirement.value) {
        uploadRequirement.value = '';
        return;
    }

    const requirement = uploadRequirement.value;
    const payload = new FormData();
    payload.append('document_name', requirement);
    payload.append('document_file', file);
    payload.append('terms_accepted', '1');

    try {
        await window.axios.post(submission.value.actions.upload_endpoint, payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
            portalToast: false,
        });
        await loadSubmission();
        documentTermsAccepted.value = false;
    } catch (error) {
        showPortalToast({
            type: 'error',
            title: 'Upload not completed',
            message: firstValidationError(error, 'Choose a supported file up to 5 MB and try again.'),
        });
    } finally {
        uploadRequirement.value = '';
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
}

function openPreview(document) {
    previewDocument.value = {
        ...document.file,
        original_name: document.file.name,
        document_name: document.requirement,
    };
}

async function submitCorrection() {
    const response = correctionResponse.value.trim();

    if (response.length < 3) {
        showPortalToast({
            type: 'error',
            title: 'Response required',
            message: 'Briefly describe what you updated before sending the record back.',
        });
        return;
    }

    isSendingCorrection.value = true;

    try {
        await window.axios.patch(submission.value.actions.correction_endpoint, { response }, {
            portalToast: false,
        });
        correctionResponse.value = '';
        showCorrectionDialog.value = false;
        await loadSubmission();
    } catch (error) {
        showPortalToast({
            type: 'error',
            title: 'Update not sent',
            message: firstValidationError(error, 'The provider could not be notified. Please try again.'),
        });
    } finally {
        isSendingCorrection.value = false;
    }
}

onMounted(loadSubmission);
</script>

<template>
    <main v-if="isLoading" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="h-5 w-36 animate-pulse rounded bg-slate-200"></div>
                <div class="mt-5 h-96 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                <div class="mt-4 h-72 animate-pulse rounded-md border border-slate-200 bg-white"></div>
            </div>
        </section>
    </main>

    <main v-else-if="errorMessage" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <a href="/dashboard/applications" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to applications
                </a>
                <section class="mt-5 rounded-md border border-rose-200 bg-white px-5 py-8 text-center shadow-sm">
                    <h1 class="text-lg font-bold text-slate-950">Submission record unavailable</h1>
                    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ errorMessage }}</p>
                    <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="loadSubmission">Try again</button>
                </section>
            </div>
        </section>
    </main>

    <ApplicantApplicationShell v-else-if="shellDetail" :detail="shellDetail">
        <FilePreviewModal
            :file="previewDocument"
            :title="previewDocument?.document_name || 'Application document'"
            :context="detail.identity.program.title"
            @close="previewDocument = null"
        />

        <ApplicationCorrectionDialog
            :open="showCorrectionDialog"
            :correction="correction"
            :response="correctionResponse"
            :processing="isSendingCorrection"
            @close="showCorrectionDialog = false"
            @submit="submitCorrection"
            @update:response="correctionResponse = $event"
        />

        <div class="mt-4 space-y-4">
            <section v-if="canRespondToCorrection" id="correction-request" class="scroll-mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-4 shadow-sm sm:px-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded bg-amber-200 text-amber-900">
                                <i class="fa-solid fa-pen-to-square text-xs" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">Provider request</p>
                                <h2 class="text-base font-bold text-slate-950">Update requested</h2>
                            </div>
                        </div>
                        <p class="mt-3 max-w-4xl text-sm leading-5 text-slate-700">{{ correction.message }}</p>
                        <div v-if="correction.targets?.length" class="mt-2 flex flex-wrap gap-2">
                            <span v-for="target in correction.targets" :key="target.key" class="rounded bg-white/80 px-2 py-1 text-xs font-bold text-slate-700">
                                {{ target.label }}
                            </span>
                        </div>
                    </div>
                    <button type="button" class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="showCorrectionDialog = true">
                        Send update
                    </button>
                </div>
            </section>

            <ApplicationDocumentList
                :documents="orderedDocuments"
                :summary="submission.document_summary"
                :can-edit="submission.actions.can_edit_files"
                :uploading-requirement="uploadRequirement"
                @preview="openPreview"
                @upload="requestUpload"
            >
                <template #terms>
                    <TermsAgreement v-model="documentTermsAccepted" context="document" />
                </template>
            </ApplicationDocumentList>

            <ApplicationSubmissionSnapshot
                :profile-rows="submission.profile_snapshot"
                :questions="submission.questions"
                :profile-href="submission.actions.profile_href"
            />

            <input
                ref="fileInput"
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                class="hidden"
                @change="uploadDocument"
            >
        </div>
    </ApplicantApplicationShell>
</template>
