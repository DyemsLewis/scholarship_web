<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const applicationWorkflowCounts = ref({
    needs_review: 0,
    waiting_activity: 0,
    ready_result: 0,
    final_decision: 0,
    all: 0,
});
const canManagePrograms = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_programs'),
));
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.can_post_scholarships
        && (
            window.portalUser?.has_full_access
            || window.portalUser?.permissions?.includes('review_applications')
        ),
));
const canManageProfile = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_profile'),
));

const recentPrograms = computed(() => scholarships.value.slice(0, 4));
const verificationDocumentCount = computed(() => Number(user.value?.verification_documents_count ?? 0));
const providerName = computed(() => user.value?.provider_name || user.value?.name || 'Provider');
const publishedProgramCount = computed(() => scholarships.value.filter((program) => program.status === 'published').length);
const totalApplicationCount = computed(() => scholarships.value.reduce(
    (total, program) => total + Number(program.applications_count ?? 0),
    0,
));
const draftPrograms = computed(() => scholarships.value.filter((program) => program.status === 'draft'));
const rejectedPrograms = computed(() => scholarships.value.filter((program) => program.status === 'rejected'));
const pendingPrograms = computed(() => scholarships.value.filter((program) => program.status === 'pending_review'));
const closedPrograms = computed(() => scholarships.value.filter((program) => program.status === 'closed'));
const applicantWorkQueues = computed(() => [
    {
        key: 'needs_review',
        label: 'Needs review',
        description: 'Check applicant details, eligibility, and files.',
        icon: 'fa-solid fa-user-check',
        href: '/provider/applications?filter=needs_review',
    },
    {
        key: 'ready_result',
        label: 'Ready for result',
        description: 'Record completed activity or formal application results.',
        icon: 'fa-solid fa-clipboard-check',
        href: '/provider/applications?filter=ready_result',
    },
    {
        key: 'final_decision',
        label: 'Final decision',
        description: 'Select, waitlist, or decline qualified applicants.',
        icon: 'fa-solid fa-award',
        href: '/provider/applications?filter=final_decision',
    },
    {
        key: 'waiting_activity',
        label: 'Waiting for activity',
        description: 'Applicants are waiting for an exam or interview.',
        icon: 'fa-solid fa-calendar-day',
        href: '/provider/applications?filter=waiting_activity',
    },
]);
const programLifecycle = computed(() => [
    {
        label: 'Needs setup',
        description: 'Drafts or programs returned for changes',
        count: draftPrograms.value.length + rejectedPrograms.value.length,
        href: '/provider/programs',
        icon: 'fa-solid fa-pen-ruler',
    },
    {
        label: 'In admin review',
        description: 'Submitted and waiting for publication review',
        count: pendingPrograms.value.length,
        href: '/provider/programs?status=pending_review',
        icon: 'fa-solid fa-hourglass-half',
    },
    {
        label: 'Published',
        description: 'Visible to eligible applicants',
        count: publishedProgramCount.value,
        href: '/provider/programs?status=published',
        icon: 'fa-solid fa-bullhorn',
    },
    {
        label: 'Closed',
        description: 'Programs no longer accepting applications',
        count: closedPrograms.value.length,
        href: '/provider/programs?status=closed',
        icon: 'fa-solid fa-box-archive',
    },
]);
const providerProfileNeedsCompletion = computed(() => [
    user.value?.provider_name,
    user.value?.provider_type,
    user.value?.provider_address,
    user.value?.provider_contact_email,
    user.value?.provider_contact_number,
].some((value) => !String(value ?? '').trim()));
const verificationActionHref = computed(() => {
    if (user.value?.can_post_scholarships || providerProfileNeedsCompletion.value) {
        return '/provider/profile';
    }

    return '/provider/profile#verification-documents';
});
const verificationPrompt = computed(() => {
    if (!user.value?.email_verified) {
        return {
            title: 'Verify your email to continue',
            description: !canManageProfile.value
                ? 'Verify your email from the sidebar. An authorized provider manager can handle organization proof.'
                : verificationDocumentCount.value
                    ? 'Your proof is saved. Verify your email so an admin can complete the provider review.'
                    : 'Verify your email, then upload organization proof for admin review.',
            action: canManageProfile.value && !verificationDocumentCount.value ? 'Upload proof' : 'View verification',
        };
    }

    if (!canManageProfile.value) {
        return {
            title: 'Provider verification needs an authorized manager',
            description: 'Ask the provider owner or staff with organization profile access to complete the verification process.',
            action: 'View verification status',
        };
    }

    if (providerProfileNeedsCompletion.value) {
        return {
            title: 'Complete your provider profile',
            description: 'Add the organization name, type, office address, and public contacts before submitting proof for admin verification.',
            action: 'Complete profile',
        };
    }

    if (user.value?.verification_status === 'rejected') {
        return {
            title: 'Update your verification proof',
            description: 'Review the admin feedback and upload a replacement document to return your account for review.',
            action: 'Upload replacement proof',
        };
    }

    if (verificationDocumentCount.value === 0) {
        return {
            title: 'Verify your provider account',
            description: 'Upload organization registration, an authorization letter, or another valid proof for admin review.',
            action: 'Upload proof',
        };
    }

    return {
        title: 'Verification is under review',
        description: 'Your proof has been submitted. You can create programs after an admin approves the provider account.',
        action: 'View verification status',
    };
});
const nextAction = computed(() => {
    if (!user.value?.can_post_scholarships) {
        return {
            eyebrow: 'Step 1 - Organization',
            title: verificationPrompt.value.title,
            description: verificationPrompt.value.description,
            href: verificationActionHref.value,
            label: verificationPrompt.value.action,
            icon: 'fa-solid fa-building-shield',
        };
    }

    if (scholarships.value.length === 0) {
        return {
            eyebrow: 'Step 2 - Programs',
            title: 'Create your first scholarship program',
            description: 'Define the support, eligible learners, required files, and selection process before sending it for admin review.',
            href: canManagePrograms.value ? '/provider/programs/create' : '/provider/programs',
            label: canManagePrograms.value ? 'Create program' : 'View programs',
            icon: 'fa-solid fa-file-circle-plus',
        };
    }

    if (applicationWorkflowCounts.value.needs_review > 0 && canReviewApplications.value) {
        return {
            eyebrow: 'Priority - Applicant review',
            title: `${applicationWorkflowCounts.value.needs_review} applicant${applicationWorkflowCounts.value.needs_review === 1 ? '' : 's'} need review`,
            description: 'Review eligibility, applicant information, and submitted files before advancing or declining each application.',
            href: '/provider/applications?filter=needs_review',
            label: 'Review applicants',
            icon: 'fa-solid fa-user-check',
        };
    }

    if (applicationWorkflowCounts.value.ready_result > 0 && canReviewApplications.value) {
        return {
            eyebrow: 'Priority - Record results',
            title: `${applicationWorkflowCounts.value.ready_result} applicant${applicationWorkflowCounts.value.ready_result === 1 ? '' : 's'} ready for a result`,
            description: 'A formal application, exam, or interview is complete and ready for your decision.',
            href: '/provider/applications?filter=ready_result',
            label: 'Record results',
            icon: 'fa-solid fa-clipboard-check',
        };
    }

    if (applicationWorkflowCounts.value.final_decision > 0 && canReviewApplications.value) {
        return {
            eyebrow: 'Priority - Final decisions',
            title: `${applicationWorkflowCounts.value.final_decision} applicant${applicationWorkflowCounts.value.final_decision === 1 ? '' : 's'} await a final decision`,
            description: 'Complete recipient selection for applicants who finished the required stages.',
            href: '/provider/applications?filter=final_decision',
            label: 'Make decisions',
            icon: 'fa-solid fa-award',
        };
    }

    const programToFix = rejectedPrograms.value[0] ?? draftPrograms.value[0];

    if (programToFix) {
        return {
            eyebrow: 'Step 2 - Programs',
            title: programToFix.status === 'rejected' ? 'Update a program that needs changes' : 'Finish a program draft',
            description: programToFix.title,
            href: canManagePrograms.value ? `/provider/programs/${programToFix.id}/edit` : `/provider/programs/${programToFix.id}`,
            label: canManagePrograms.value ? 'Continue setup' : 'Open program',
            icon: 'fa-solid fa-pen-ruler',
        };
    }

    if (pendingPrograms.value.length && publishedProgramCount.value === 0) {
        return {
            eyebrow: 'Step 2 - Programs',
            title: 'Program review is in progress',
            description: 'An administrator is reviewing your submitted program. You can check its status while you wait.',
            href: '/provider/programs?status=pending_review',
            label: 'Check program status',
            icon: 'fa-solid fa-hourglass-half',
        };
    }

    if (applicationWorkflowCounts.value.waiting_activity > 0 && canReviewApplications.value) {
        return {
            eyebrow: 'Applicant activities',
            title: `${applicationWorkflowCounts.value.waiting_activity} applicant${applicationWorkflowCounts.value.waiting_activity === 1 ? '' : 's'} waiting for an activity`,
            description: 'Check the shared exam or interview schedule and keep applicants moving through the selection process.',
            href: '/provider/applications?filter=waiting_activity',
            label: 'Check activities',
            icon: 'fa-solid fa-calendar-day',
        };
    }

    return {
        eyebrow: 'Published programs',
        title: 'Monitor your open programs',
        description: 'Your programs are ready for applicant pre-screening. Review activity and keep deadlines or public details current.',
        href: '/provider/programs',
        label: 'View programs',
        icon: 'fa-solid fa-binoculars',
    };
});

function verificationLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function verificationClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function programActionLabel(program) {
    if (program.status === 'draft') return 'Continue setup';
    if (program.status === 'rejected') return 'Fix and resubmit';
    if (program.status === 'pending_review') return 'View review status';
    if (program.status === 'published' && Number(program.pending_review_applications_count ?? 0) > 0) return 'Review applicants';

    return 'Open workspace';
}

function programActionHref(program) {
    if (['draft', 'rejected'].includes(program.status) && canManagePrograms.value) {
        return `/provider/programs/${program.id}/edit`;
    }

    if (program.status === 'published' && Number(program.pending_review_applications_count ?? 0) > 0 && canReviewApplications.value) {
        return `/provider/programs/${program.id}/applications?workspace=applications`;
    }

    return `/provider/programs/${program.id}`;
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/dashboard/data');

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
        applicationWorkflowCounts.value = {
            ...applicationWorkflowCounts.value,
            ...(response.data.application_workflow_counts ?? {}),
        };
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider dashboard.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadProviderData);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <header class="provider-hero">
                    <div>
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Provider workspace
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Welcome, {{ providerName }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Move from organization setup to applicant outcomes through one clear scholarship workflow.
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="provider-content-stack">
                    <section
                        :class="[
                            'relative overflow-hidden rounded-lg border p-5 shadow-sm sm:p-6',
                            user?.can_post_scholarships
                                ? 'border-slate-800 bg-slate-950 text-white'
                                : 'border-amber-200 bg-amber-50',
                        ]"
                    >
                        <div v-if="user?.can_post_scholarships" class="pointer-events-none absolute -right-16 -top-24 h-64 w-64 rounded-full border-[42px] border-amber-300/10"></div>
                        <div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-start gap-4">
                                <span
                                    :class="[
                                        'grid h-11 w-11 shrink-0 place-items-center rounded-md',
                                        user?.can_post_scholarships ? 'bg-amber-300 text-slate-950' : 'bg-amber-200 text-amber-900',
                                    ]"
                                >
                                    <i :class="[nextAction.icon, 'text-sm']" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p :class="['text-[10px] font-bold uppercase tracking-[0.16em]', user?.can_post_scholarships ? 'text-amber-300' : 'text-amber-800']">
                                            {{ nextAction.eyebrow }}
                                        </p>
                                        <span :class="['rounded px-2 py-1 text-[9px] font-bold uppercase', verificationClass(user?.verification_status)]">
                                            {{ verificationLabel(user?.verification_status) }} provider
                                        </span>
                                    </div>
                                    <h3 :class="['mt-1 text-xl font-bold', user?.can_post_scholarships ? 'text-white' : 'text-slate-950']">
                                        {{ nextAction.title }}
                                    </h3>
                                    <p :class="['mt-1 max-w-3xl text-sm leading-6', user?.can_post_scholarships ? 'text-slate-300' : 'text-amber-950/80']">
                                        {{ nextAction.description }}
                                    </p>
                                    <p v-if="!user?.can_post_scholarships && user?.verification_notes" class="mt-2 text-xs leading-5 text-amber-900">
                                        <span class="font-bold">Admin note:</span> {{ user.verification_notes }}
                                    </p>
                                </div>
                            </div>
                            <a
                                :href="nextAction.href"
                                :class="[
                                    'inline-flex shrink-0 items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-bold transition',
                                    user?.can_post_scholarships
                                        ? 'bg-white text-slate-950 hover:bg-amber-300'
                                        : 'bg-slate-950 text-white hover:bg-slate-800',
                                ]"
                            >
                                {{ nextAction.label }}
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </a>
                        </div>
                    </section>

                    <div :class="['grid items-stretch gap-4', canReviewApplications ? 'xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]' : '']">
                        <section v-if="canReviewApplications" class="provider-panel h-full overflow-hidden">
                            <header class="flex items-end justify-between gap-4 border-b border-slate-200 px-5 py-4">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Applicant work</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">What needs your attention</h3>
                                </div>
                                <a href="/provider/applications" class="shrink-0 text-xs font-bold text-slate-600 transition hover:text-slate-950">
                                    All applications <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i>
                                </a>
                            </header>

                            <div class="grid sm:grid-cols-2">
                                <a
                                    v-for="(queue, index) in applicantWorkQueues"
                                    :key="queue.key"
                                    :href="queue.href"
                                    :class="[
                                        'group flex min-h-28 items-start gap-3 border-slate-200 px-5 py-4 transition hover:bg-slate-50',
                                        index < applicantWorkQueues.length - 1 ? 'border-b' : '',
                                        index === 2 ? 'sm:border-b-0' : '',
                                        index % 2 === 0 ? 'sm:border-r' : '',
                                    ]"
                                >
                                    <span
                                        :class="[
                                            'grid h-9 w-9 shrink-0 place-items-center rounded-md text-xs',
                                            Number(applicationWorkflowCounts[queue.key] ?? 0) > 0
                                                ? 'bg-amber-100 text-amber-800'
                                                : 'bg-slate-100 text-slate-500',
                                        ]"
                                    >
                                        <i :class="queue.icon" aria-hidden="true"></i>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center justify-between gap-3">
                                            <span class="text-sm font-bold text-slate-950">{{ queue.label }}</span>
                                            <span class="text-lg font-bold leading-none text-slate-950">{{ applicationWorkflowCounts[queue.key] ?? 0 }}</span>
                                        </span>
                                        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ queue.description }}</span>
                                    </span>
                                </a>
                            </div>
                        </section>

                        <section class="provider-panel h-full overflow-hidden">
                            <header class="flex items-end justify-between gap-4 border-b border-slate-200 px-5 py-4">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Program lifecycle</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">Where programs stand</h3>
                                </div>
                                <a href="/provider/programs" class="shrink-0 text-xs font-bold text-slate-600 transition hover:text-slate-950">
                                    View all <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i>
                                </a>
                            </header>
                            <div class="divide-y divide-slate-200">
                                <a
                                    v-for="stage in programLifecycle"
                                    :key="stage.label"
                                    :href="stage.href"
                                    class="group flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50"
                                >
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-slate-100 text-[11px] text-slate-600">
                                        <i :class="stage.icon" aria-hidden="true"></i>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-bold text-slate-950">{{ stage.label }}</span>
                                        <span class="mt-0.5 block truncate text-[11px] text-slate-500">{{ stage.description }}</span>
                                    </span>
                                    <span class="text-base font-bold text-slate-950">{{ stage.count }}</span>
                                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-300 transition group-hover:text-slate-700" aria-hidden="true"></i>
                                </a>
                            </div>
                            <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs text-slate-600">
                                <span v-if="canReviewApplications">{{ totalApplicationCount }} total application{{ totalApplicationCount === 1 ? '' : 's' }}</span>
                                <span>{{ scholarships.length }} program{{ scholarships.length === 1 ? '' : 's' }}</span>
                            </div>
                        </section>
                    </div>

                    <section class="provider-panel overflow-hidden">
                        <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Program cycle</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">Programs in motion</h3>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Each row opens the next useful action for that program.</p>
                            </div>
                            <div class="flex gap-2">
                                <a v-if="user?.can_post_scholarships && canManagePrograms" href="/provider/programs/create" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">New program</a>
                                <a href="/provider/programs" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">View all programs</a>
                            </div>
                        </header>

                        <div v-if="recentPrograms.length" class="divide-y divide-slate-200">
                            <a
                                v-for="program in recentPrograms"
                                :key="program.id"
                                :href="programActionHref(program)"
                                class="group flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50"
                            >
                                <img :src="program.image_url || '/uploads/scholarship-default.jpg'" :alt="program.title" class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200">
                                <span class="min-w-0 flex-1">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="truncate text-sm font-bold text-slate-950">{{ program.title }}</span>
                                        <span :class="['hidden shrink-0 rounded px-2 py-1 text-[9px] font-bold uppercase sm:inline-flex', statusClass(program.status)]">{{ verificationLabel(program.status) }}</span>
                                    </span>
                                    <span class="mt-0.5 block text-xs text-slate-500">
                                        <template v-if="canReviewApplications">
                                            {{ program.applications_count ?? 0 }} applicant{{ Number(program.applications_count ?? 0) === 1 ? '' : 's' }}
                                            <span class="mx-1 text-slate-300">/</span>
                                        </template>
                                        Updated {{ program.updated_at || 'recently' }}
                                    </span>
                                </span>
                                <span class="hidden shrink-0 text-xs font-bold text-slate-600 sm:block">{{ programActionLabel(program) }}</span>
                                <i class="fa-solid fa-arrow-right text-xs text-slate-300 transition group-hover:text-slate-700" aria-hidden="true"></i>
                            </a>
                        </div>
                        <div v-else class="flex flex-col items-start gap-3 px-5 py-6 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-bold text-slate-950">No programs yet</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Complete organization verification, then create the first program.</p>
                            </div>
                            <a :href="verificationActionHref" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">Start setup</a>
                        </div>
                    </section>

                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
