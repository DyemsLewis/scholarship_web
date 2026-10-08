<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

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
const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(permission),
);
const canManagePrograms = computed(() => Boolean(
    hasPermission('manage_programs'),
));
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.can_post_scholarships
        && ['verify_applications', 'manage_selection_activities', 'record_final_decisions']
            .some((permission) => hasPermission(permission)),
));
const canVerifyApplications = computed(() => (
    window.portalUser?.can_post_scholarships && hasPermission('verify_applications')
));
const canManageSelectionActivities = computed(() => (
    window.portalUser?.can_post_scholarships && hasPermission('manage_selection_activities')
));
const canRecordFinalDecisions = computed(() => (
    window.portalUser?.can_post_scholarships && hasPermission('record_final_decisions')
));
const canAccessRecipientWorkflow = computed(() => Boolean(
    window.portalUser?.can_post_scholarships
        && ['manage_recipients', 'manage_monitoring', 'manage_benefit_releases']
            .some((permission) => hasPermission(permission)),
));
const canManageProfile = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_profile'),
));
const canManageReports = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_reports'),
));
const canManageBilling = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_billing'),
));
const canViewPrograms = computed(() => (
    canManagePrograms.value || canReviewApplications.value || canAccessRecipientWorkflow.value
));
const roleLabel = computed(() => {
    if (!window.portalUser?.is_managed_account) return 'Organization owner';

    return String(window.portalUser?.account_title || 'Team member')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
});
const recentPrograms = computed(() => scholarships.value.slice(0, 5));
const verificationDocumentCount = computed(() => Number(user.value?.verification_documents_count ?? 0));
const providerName = computed(() => user.value?.provider_name || user.value?.name || 'Provider');
const publishedProgramCount = computed(() => scholarships.value.filter((program) => program.status === 'published').length);
const openApplicationWorkCount = computed(() => [
    'needs_review',
    'waiting_activity',
    'ready_result',
    'final_decision',
].reduce((total, key) => total + Number(applicationWorkflowCounts.value[key] ?? 0), 0));
const draftPrograms = computed(() => scholarships.value.filter((program) => program.status === 'draft'));
const rejectedPrograms = computed(() => scholarships.value.filter((program) => program.status === 'rejected'));
const pendingPrograms = computed(() => scholarships.value.filter((program) => program.status === 'pending_review'));
const applicantWorkQueues = computed(() => [
    ...(canVerifyApplications.value ? [{
        key: 'needs_review',
        label: 'Needs review',
        description: 'Check applicant details, eligibility, and files.',
        icon: 'fa-solid fa-user-check',
        href: '/provider/workspaces/reviews',
    }] : []),
    ...(canManageSelectionActivities.value ? [{
        key: 'ready_result',
        label: 'Ready for result',
        description: 'Record results for completed scheduled activities.',
        icon: 'fa-solid fa-clipboard-check',
        href: '/provider/workspaces/selection?queue=results',
    }] : []),
    ...(canRecordFinalDecisions.value ? [{
        key: 'final_decision',
        label: 'Final decision',
        description: 'Select, waitlist, or decline qualified applicants.',
        icon: 'fa-solid fa-award',
        href: '/provider/workspaces/decisions',
    }] : []),
    ...(canManageSelectionActivities.value ? [{
        key: 'waiting_activity',
        label: 'Waiting for activity',
        description: 'Applicants are waiting for a formal application, exam, or interview.',
        icon: 'fa-solid fa-calendar-day',
        href: '/provider/workspaces/selection?queue=upcoming',
    }] : []),
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
        return '/provider/profile/details';
    }

    return '/provider/profile/verification';
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

    if (!canViewPrograms.value && canManageReports.value) {
        return {
            eyebrow: 'Your workspace - Reports',
            title: 'Review provider and applicant concerns',
            description: 'Open the report queue, check the concern details, and record the appropriate response or resolution.',
            href: '/provider/workspaces/support',
            label: 'Open reports',
            icon: 'fa-solid fa-circle-exclamation',
        };
    }

    if (!canViewPrograms.value && canManageBilling.value) {
        return {
            eyebrow: 'Your workspace - Services',
            title: 'Manage Tulay Aral service requests',
            description: 'Track purchased support, meeting schedules, shared files, payments, and service progress.',
            href: '/provider/workspaces/billing',
            label: 'Open service requests',
            icon: 'fa-solid fa-headset',
        };
    }

    if (!canViewPrograms.value) {
        return {
            eyebrow: 'Your workspace - Profile',
            title: 'Review your account information',
            description: 'Your access is limited to your personal credentials and the organization information shared with your account.',
            href: '/provider/profile/details',
            label: 'Open profile',
            icon: 'fa-solid fa-id-badge',
        };
    }

    if (scholarships.value.length === 0) {
        return {
            eyebrow: 'Step 2 - Programs',
            title: 'Create your first scholarship program',
            description: 'Define the support, eligible learners, required files, and selection process before sending it for admin review.',
            href: canManagePrograms.value ? '/provider/programs/create' : '/provider/workspaces/programs',
            label: canManagePrograms.value ? 'Create program' : 'View programs',
            icon: 'fa-solid fa-file-circle-plus',
        };
    }

    if (applicationWorkflowCounts.value.needs_review > 0 && canVerifyApplications.value) {
        return {
            eyebrow: 'Priority - Applicant review',
            title: `${applicationWorkflowCounts.value.needs_review} applicant${applicationWorkflowCounts.value.needs_review === 1 ? '' : 's'} need review`,
            description: 'Review eligibility, applicant information, and submitted files before advancing or declining each application.',
            href: '/provider/workspaces/reviews',
            label: 'Review applicants',
            icon: 'fa-solid fa-user-check',
        };
    }

    if (applicationWorkflowCounts.value.ready_result > 0 && canManageSelectionActivities.value) {
        return {
            eyebrow: 'Priority - Record results',
            title: `${applicationWorkflowCounts.value.ready_result} applicant${applicationWorkflowCounts.value.ready_result === 1 ? '' : 's'} ready for a result`,
            description: 'A scheduled formal application, exam, or interview is complete and ready for your decision.',
            href: '/provider/workspaces/selection?queue=results',
            label: 'Record results',
            icon: 'fa-solid fa-clipboard-check',
        };
    }

    if (applicationWorkflowCounts.value.final_decision > 0 && canRecordFinalDecisions.value) {
        return {
            eyebrow: 'Priority - Final decisions',
            title: `${applicationWorkflowCounts.value.final_decision} applicant${applicationWorkflowCounts.value.final_decision === 1 ? '' : 's'} await a final decision`,
            description: 'Complete recipient selection for applicants who finished the required stages.',
            href: '/provider/workspaces/decisions',
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
            href: '/provider/workspaces/programs/review',
            label: 'Check program status',
            icon: 'fa-solid fa-hourglass-half',
        };
    }

    if (applicationWorkflowCounts.value.waiting_activity > 0 && canManageSelectionActivities.value) {
        return {
            eyebrow: 'Applicant activities',
            title: `${applicationWorkflowCounts.value.waiting_activity} applicant${applicationWorkflowCounts.value.waiting_activity === 1 ? '' : 's'} waiting for an activity`,
            description: 'Check formal application, exam, and interview schedules to keep applicants moving.',
            href: '/provider/workspaces/selection?queue=upcoming',
            label: 'Check activities',
            icon: 'fa-solid fa-calendar-day',
        };
    }

    return {
        eyebrow: 'Published programs',
        title: 'Monitor your open programs',
        description: 'Your programs are ready for applicant pre-screening. Review activity and keep deadlines or public details current.',
        href: '/provider/workspaces/programs',
        label: 'View programs',
        icon: 'fa-solid fa-binoculars',
    };
});

function verificationLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'pending_review') {
        return 'bg-amber-100 text-amber-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-slate-100 text-slate-700';
}

function statusIcon(status) {
    return {
        published: 'fa-solid fa-circle-check',
        rejected: 'fa-solid fa-triangle-exclamation',
        pending_review: 'fa-solid fa-hourglass-half',
        closed: 'fa-solid fa-lock',
    }[status] ?? 'fa-regular fa-pen-to-square';
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
        return `/provider/programs/${program.id}/applications/review`;
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
                <ProviderWorkspaceState
                    v-if="isLoading"
                    title="Loading manager overview"
                    message="Preparing the organization summary and current handoff counts."
                />

                <ProviderWorkspaceState
                    v-else-if="errorMessage"
                    tone="error"
                    title="Manager overview is unavailable"
                    :message="errorMessage"
                />

                <template v-else>
                    <ProviderPageHeader
                        role-key="manager"
                        eyebrow="Manager workspace"
                        title="Provider management"
                        description="Review organization readiness and current workload, then open the dedicated workspace responsible for the next action."
                        icon="fa-solid fa-briefcase"
                        :show-role-guide="false"
                    >
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400" aria-hidden="true"></i>{{ providerName }}</span>
                            <span><i class="fa-solid fa-user-tie mr-2 text-slate-400" aria-hidden="true"></i>{{ roleLabel }}</span>
                            <span><i class="fa-solid fa-key mr-2 text-slate-400" aria-hidden="true"></i>Full operational access</span>
                        </template>
                        <template #actions>
                            <a href="/provider/profile/details" class="border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 hover:bg-slate-50">
                                <i class="fa-solid fa-building-circle-check mr-2 text-xs text-slate-400" aria-hidden="true"></i>Organization
                            </a>
                            <a v-if="user?.can_post_scholarships && canManagePrograms" href="/provider/programs/create" class="bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800">
                                <i class="fa-solid fa-plus mr-2 text-xs text-amber-300" aria-hidden="true"></i>New program
                            </a>
                        </template>
                    </ProviderPageHeader>

                    <section
                        :class="[
                            'mt-4 border border-slate-300 border-l-4 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]',
                            user?.can_post_scholarships ? 'border-l-slate-950' : 'border-l-amber-500',
                        ]"
                    >
                        <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <i :class="[nextAction.icon, 'mt-1 w-4 shrink-0 text-center text-amber-600']" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p class="text-[0.65rem] font-black uppercase tracking-[0.14em] text-amber-700">{{ nextAction.eyebrow }}</p>
                                    <h2 class="mt-1 text-base font-bold text-slate-950">{{ nextAction.title }}</h2>
                                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ nextAction.description }}</p>
                                    <p v-if="!user?.can_post_scholarships && user?.verification_notes" class="mt-2 text-xs leading-5 text-amber-800"><strong>Admin note:</strong> {{ user.verification_notes }}</p>
                                </div>
                            </div>
                            <a :href="nextAction.href" class="inline-flex shrink-0 items-center justify-center bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">
                                {{ nextAction.label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i>
                            </a>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="grid grid-cols-2 sm:grid-cols-4">
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-b-0">
                                <p class="flex items-center gap-2 text-xs font-bold text-slate-500"><i class="fa-regular fa-folder-open" aria-hidden="true"></i>Programs</p>
                                <p class="mt-2 text-2xl font-black text-slate-950">{{ scholarships.length }}</p>
                                <p class="mt-1 text-xs text-slate-500">Organization total</p>
                            </div>
                            <div class="border-b border-l border-slate-200 px-5 py-4 sm:border-b-0">
                                <p class="flex items-center gap-2 text-xs font-bold text-slate-500"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i>Published</p>
                                <p class="mt-2 text-2xl font-black text-slate-950">{{ publishedProgramCount }}</p>
                                <p class="mt-1 text-xs text-slate-500">Visible to applicants</p>
                            </div>
                            <div class="px-5 py-4 sm:border-l sm:border-slate-200">
                                <p class="flex items-center gap-2 text-xs font-bold text-slate-500"><i class="fa-solid fa-list-check" aria-hidden="true"></i>Application work</p>
                                <p :class="['mt-2 text-2xl font-black', openApplicationWorkCount ? 'text-amber-700' : 'text-slate-950']">{{ openApplicationWorkCount }}</p>
                                <p class="mt-1 text-xs text-slate-500">Across active handoffs</p>
                            </div>
                            <div class="border-l border-slate-200 px-5 py-4">
                                <p class="flex items-center gap-2 text-xs font-bold text-slate-500"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Organization</p>
                                <p :class="['mt-2 text-sm font-black uppercase tracking-wide', user?.can_post_scholarships ? 'text-emerald-700' : 'text-amber-700']">{{ verificationLabel(user?.verification_status) }}</p>
                                <p class="mt-1 text-xs text-slate-500">Verification status</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="canReviewApplications" class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fa-solid fa-arrow-right-arrow-left text-sm text-slate-500" aria-hidden="true"></i>Operational handoffs</h2>
                                <p class="mt-1 text-sm text-slate-500">Each row opens its dedicated role workspace; detailed records stay off this page.</p>
                            </div>
                            <p class="text-sm font-semibold text-slate-600"><strong :class="openApplicationWorkCount ? 'text-amber-700' : 'text-emerald-700'">{{ openApplicationWorkCount }}</strong> open item{{ openApplicationWorkCount === 1 ? '' : 's' }}</p>
                        </header>
                        <div class="portal-table-scroll">
                            <table class="portal-data-table min-w-[48rem] table-fixed">
                                <caption class="sr-only">Application workflow handoffs</caption>
                                <colgroup><col class="w-[25%]"><col class="w-[48%]"><col class="w-[12%]"><col class="w-[15%]"></colgroup>
                                <thead><tr><th scope="col">Workspace</th><th scope="col">Responsibility</th><th scope="col" class="text-right">Open</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="queue in applicantWorkQueues" :key="queue.key">
                                        <td class="font-semibold text-slate-950"><i :class="[queue.icon, 'mr-2 w-4 text-center text-slate-500']" aria-hidden="true"></i>{{ queue.label }}</td>
                                        <td class="text-slate-600">{{ queue.description }}</td>
                                        <td :class="['text-right text-base font-black', Number(applicationWorkflowCounts[queue.key] ?? 0) ? 'text-amber-700' : 'text-slate-950']">{{ applicationWorkflowCounts[queue.key] ?? 0 }}</td>
                                        <td><a :href="queue.href" class="inline-flex items-center border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">Open<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section v-if="canViewPrograms" class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fa-solid fa-table-list text-sm text-slate-500" aria-hidden="true"></i>Recently updated programs</h2>
                                <p class="mt-1 text-sm text-slate-500">Showing only the five most recent records.</p>
                            </div>
                            <a href="/provider/workspaces/programs" class="inline-flex items-center border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">Open program workspace<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem]" aria-hidden="true"></i></a>
                        </header>

                        <div v-if="recentPrograms.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[64rem] table-fixed">
                                <caption class="sr-only">Recently updated scholarship programs</caption>
                                <colgroup><col class="w-[35%]"><col class="w-[15%]"><col class="w-[17%]"><col class="w-[17%]"><col class="w-[16%]"></colgroup>
                                <thead><tr><th scope="col">Program</th><th scope="col">Status</th><th scope="col">Applicants</th><th scope="col">Updated</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="program in recentPrograms" :key="program.id">
                                        <td>
                                            <div class="flex min-w-0 items-start gap-2.5">
                                                <i class="fa-regular fa-folder mt-1 w-4 shrink-0 text-center text-slate-400" aria-hidden="true"></i>
                                                <div class="min-w-0"><p class="truncate font-semibold text-slate-950">{{ program.title }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ [program.category, program.program_cycle].filter(Boolean).join(' · ') || 'Program record' }}</p></div>
                                            </div>
                                        </td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', statusClass(program.status)]"><i :class="statusIcon(program.status)" aria-hidden="true"></i>{{ verificationLabel(program.status) }}</span></td>
                                        <td><p class="font-bold text-slate-950">{{ program.applications_count ?? 0 }}</p><p class="mt-0.5 text-xs text-slate-500">{{ program.pending_review_applications_count ?? 0 }} need review</p></td>
                                        <td class="text-slate-600"><i class="fa-regular fa-clock mr-1.5 text-xs text-slate-400" aria-hidden="true"></i>{{ program.updated_at || 'Recently' }}</td>
                                        <td><a :href="programActionHref(program)" class="inline-flex items-center border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ programActionLabel(program) }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-6 py-10 text-center">
                            <i class="fa-regular fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No program records yet</h3>
                            <p class="mt-1 text-sm text-slate-500">Complete organization verification, then create the first scholarship program.</p>
                            <a :href="verificationActionHref" class="mt-4 inline-flex border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Start setup</a>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
