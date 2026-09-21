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
            { href: '/dashboard/applications?view=monitoring', label: 'Recipient monitoring', exact: true },
        ],
    },
    {
        href: '/dashboard/documents',
        label: 'Documents',
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
    />

    <button
        v-if="!isReportModalOpen"
        type="button"
        class="fixed bottom-5 right-5 z-40 inline-flex items-center gap-2 rounded-full bg-amber-300 px-4 py-3 text-sm font-bold text-slate-950 shadow-[0_14px_35px_rgba(8,20,38,0.28)] ring-2 ring-white transition hover:-translate-y-0.5 hover:bg-amber-200 sm:bottom-6 sm:right-6"
        aria-label="Report a problem"
        @click="openReportModal"
    >
        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-950 text-amber-200">
            <i class="fa-solid fa-circle-exclamation text-xs" aria-hidden="true"></i>
        </span>
        Report
    </button>

    <ApplicantReportModal v-model="isReportModalOpen" :initial-category="reportCategory" />
</template>
