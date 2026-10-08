<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import ApplicantReportModal from './ApplicantReportModal.vue';
import RoleSidebar from './RoleSidebar.vue';

const isReportModalOpen = ref(false);
const reportCategory = ref('');

const navLinks = [
    {
        href: '/dashboard',
        label: 'Dashboard',
        icon: 'fa-solid fa-gauge-high',
        exact: true,
    },
    {
        href: '/dashboard/scholarships',
        label: 'Scholarships',
        icon: 'fa-solid fa-graduation-cap',
        children: [
            { href: '/dashboard/scholarships', label: 'Find scholarships', exact: true, queryless: true },
            { href: '/dashboard/providers', label: 'Browse providers', activePaths: ['/dashboard/providers'] },
            { href: '/dashboard/scholarships?view=saved', label: 'Saved scholarships', exact: true },
            { href: '/dashboard/scholarships?view=compare', label: 'Compare scholarships', exact: true },
        ],
    },
    {
        href: '/dashboard/applications',
        label: 'Applications',
        icon: 'fa-solid fa-file-signature',
        children: [
            { href: '/dashboard/applications', label: 'Active applications', exact: true, queryless: true },
            { href: '/dashboard/applications?view=action', label: 'Needs my action', exact: true },
            { href: '/dashboard/applications?view=completed', label: 'Completed', exact: true },
        ],
    },
    {
        href: '/dashboard/monitoring',
        label: 'Monitoring',
        icon: 'fa-solid fa-heart-pulse',
        activePaths: ['/dashboard/monitoring'],
    },
    {
        href: '/dashboard/documents',
        label: 'My Documents',
        icon: 'fa-solid fa-folder-open',
        children: [
            { href: '/dashboard/documents', label: 'Prepared files', exact: true, queryless: true },
            { href: '/dashboard/documents?view=applications', label: 'Application files', exact: true },
        ],
    },
    {
        href: '/dashboard/profile',
        label: 'Profile',
        icon: 'fa-solid fa-id-card',
        children: [
            { href: '/dashboard/profile', label: 'Profile overview', exact: true, queryless: true },
            { href: '/dashboard/profile?section=personal', label: 'Personal information', exact: true },
            { href: '/dashboard/profile?section=academic', label: 'Education', exact: true },
            { href: '/dashboard/profile?section=household', label: 'Household and guardian', exact: true },
            { href: '/dashboard/profile?section=background', label: 'Goals and involvement', exact: true },
            { href: '/dashboard/profile?section=location', label: 'Location', exact: true },
            { href: '/dashboard/profile?section=verification', label: 'Supporting evidence', exact: true },
        ],
    },
];

function openReportModal(category = '') {
    reportCategory.value = category;
    isReportModalOpen.value = true;
}

function openRequestedReport(event) {
    openReportModal(event.detail?.category === 'privacy' ? 'privacy' : '');
}

onMounted(() => {
    window.addEventListener('portal:open-report', openRequestedReport);
});

onUnmounted(() => {
    window.removeEventListener('portal:open-report', openRequestedReport);
});
</script>

<template>
    <RoleSidebar
        title="Applicant"
        subtitle="Scholarship Workspace"
        icon="fa-solid fa-award"
        home-href="/dashboard"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue using the scholarship portal."
        mobile-collapsible
    >
        <template #account-actions>
            <button
                type="button"
                class="group flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold text-slate-400 transition hover:bg-white/[0.05] hover:text-white"
                @click="openReportModal"
            >
                <span class="grid h-6 w-6 shrink-0 place-items-center text-xs text-slate-500 transition group-hover:text-amber-300">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                </span>
                Report a problem
            </button>
        </template>
    </RoleSidebar>

    <ApplicantReportModal v-model="isReportModalOpen" :initial-category="reportCategory" />
</template>
