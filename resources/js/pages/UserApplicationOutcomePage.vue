<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ApplicantApplicationShell from '../components/application-detail/ApplicantApplicationShell.vue';
import ApplicationOutcomeRecord from '../components/application-detail/ApplicationOutcomeRecord.vue';
import RecipientAgreementDialog from '../components/application-detail/RecipientAgreementDialog.vue';
import { showPortalToast } from '../support/portalToast';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');
const showAgreement = ref(false);
const agreementTermsAccepted = ref(false);
const agreementNote = ref('');
const isSubmittingAgreement = ref(false);

const outcome = computed(() => detail.value?.content ?? {});
const agreement = computed(() => outcome.value.agreement ?? null);
const shellDetail = computed(() => {
    if (!detail.value) {
        return null;
    }

    const sourceDecision = detail.value.decision ?? {};
    const decision = {
        ...sourceDecision,
        action: sourceDecision.action ? { ...sourceDecision.action } : null,
    };
    const actionPath = decision.action?.href
        ? new URL(decision.action.href, window.location.origin).pathname
        : '';

    if (actionPath === window.location.pathname) {
        decision.action.href = agreement.value ? '#recipient-agreement' : '#application-result';
        decision.action.label = agreement.value?.can_respond ? 'Review agreement' : 'Review result';
    }

    return { ...detail.value, decision };
});

async function loadOutcome() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`, {
            params: { view: 'outcome' },
        });
        detail.value = response.data.detail;

        if (new URLSearchParams(window.location.search).get('action') === 'agreement'
            && agreement.value?.can_respond) {
            showAgreement.value = true;
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? 'We could not load the application outcome.';
    } finally {
        isLoading.value = false;
    }
}

async function respondToAgreement(response) {
    if (response === 'accepted' && !agreementTermsAccepted.value) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that you reviewed the complete recipient agreement before accepting.',
        });
        return;
    }

    if (response === 'declined' && agreementNote.value.trim().length < 5) {
        showPortalToast({
            type: 'error',
            title: 'Reason required',
            message: 'Briefly explain why you cannot accept the recipient agreement.',
        });
        return;
    }

    isSubmittingAgreement.value = true;

    try {
        const result = await window.axios.patch(outcome.value.response_endpoint, {
            response,
            terms_accepted: response === 'accepted' ? agreementTermsAccepted.value : false,
            note: agreementNote.value.trim() || null,
        }, {
            portalToast: false,
        });
        showPortalToast({
            title: response === 'accepted' ? 'Agreement accepted' : 'Response sent',
            message: result.data.message,
        });
        agreementTermsAccepted.value = false;
        agreementNote.value = '';
        showAgreement.value = false;
        await loadOutcome();
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Response not saved',
            message: errors
                ? Object.values(errors).flat()[0]
                : (error.response?.data?.message ?? 'Please try again.'),
        });
    } finally {
        isSubmittingAgreement.value = false;
    }
}

onMounted(loadOutcome);
</script>

<template>
    <main v-if="isLoading" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="h-5 w-36 animate-pulse rounded bg-slate-200"></div>
                <div class="mt-5 h-44 animate-pulse rounded-md border border-slate-200 bg-white"></div>
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
                    <h1 class="text-lg font-bold text-slate-950">Outcome unavailable</h1>
                    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ errorMessage }}</p>
                    <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="loadOutcome">Try again</button>
                </section>
            </div>
        </section>
    </main>

    <ApplicantApplicationShell v-else-if="shellDetail" :detail="shellDetail">
        <RecipientAgreementDialog
            :open="showAgreement"
            :agreement="agreement"
            :terms-accepted="agreementTermsAccepted"
            :note="agreementNote"
            :processing="isSubmittingAgreement"
            @close="showAgreement = false"
            @respond="respondToAgreement"
            @update:terms-accepted="agreementTermsAccepted = $event"
            @update:note="agreementNote = $event"
        />

        <div class="mt-4 space-y-4">
            <section v-if="outcome.pending" id="application-result" class="scroll-mt-4 rounded-md border border-slate-300 bg-white px-5 py-10 text-center shadow-sm">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-600">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                </span>
                <h2 class="mt-3 text-lg font-bold text-slate-950">{{ outcome.pending.title }}</h2>
                <p class="mx-auto mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ outcome.pending.description }}</p>
            </section>

            <ApplicationOutcomeRecord v-if="outcome.available" :outcome="outcome" />

            <section v-if="agreement" id="recipient-agreement" class="scroll-mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <div class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                            <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-bold text-slate-950">Recipient agreement</h2>
                                <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', agreement.status === 'accepted' ? 'bg-emerald-100 text-emerald-800' : agreement.status === 'declined' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800']">
                                    {{ agreement.status_label }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm leading-5 text-slate-600">
                                {{ agreement.can_respond ? 'Review the full support and responsibilities before responding.' : 'The terms and your recorded response remain available here.' }}
                            </p>
                        </div>
                    </div>
                    <button type="button" class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="showAgreement = true">
                        {{ agreement.can_respond ? 'Review and respond' : 'View agreement' }}
                    </button>
                </div>
            </section>

            <section v-if="outcome.recipient_support" class="rounded-md border border-emerald-300 bg-emerald-50/70 px-4 py-4 shadow-sm sm:px-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-700">
                            <i class="fa-solid fa-heart-pulse" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-700">Next workspace</p>
                            <h2 class="mt-1 text-base font-bold text-slate-950">{{ outcome.recipient_support.title }}</h2>
                            <p class="mt-1 text-sm leading-5 text-slate-600">{{ outcome.recipient_support.description }}</p>
                        </div>
                    </div>
                    <a :href="outcome.recipient_support.href" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">
                        Open recipient support
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </section>
        </div>
    </ApplicantApplicationShell>
</template>
