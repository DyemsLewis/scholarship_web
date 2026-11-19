<script setup>
import { computed } from 'vue';
import ApplicantSidebar from '../ApplicantSidebar.vue';
import ApplicationDecisionBanner from './ApplicationDecisionBanner.vue';
import ApplicantRecordTabs from '../ApplicantRecordTabs.vue';

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
});

const statusClass = computed(() => ({
    positive: 'student-badge-positive',
    negative: 'student-badge-negative',
    attention: 'student-badge-attention',
    active: 'student-badge-info',
}[props.detail.identity?.status?.tone] ?? ''));

const snapshotIcons = {
    stage: 'fa-solid fa-route',
    deadline: 'fa-regular fa-calendar',
    files: 'fa-regular fa-folder-open',
    review: 'fa-solid fa-clipboard-check',
};

const applicationVisual = computed(() => {
    const program = props.detail.identity?.program;
    if (program?.cover_image_url) {
        return { src: program.cover_image_url, alt: `${program.title} background`, focus: 'object-center' };
    }

    const view = props.detail.view ?? 'summary';
    const subject = `${props.detail.identity?.program?.title ?? ''} ${props.detail.identity?.program?.category ?? ''}`.toLowerCase();

    const sectionVisuals = {
        submission: {
            src: '/images/application-documents.jpg',
            alt: 'A student preparing scholarship application documents',
            focus: 'object-center',
        },
        activities: {
            src: '/images/filipino-students-workshop.jpg',
            alt: 'Filipino students participating in a learning workshop',
            focus: 'object-center',
        },
        outcome: {
            src: props.detail.identity?.status?.tone === 'positive'
                ? '/images/filipino-graduates.jpg'
                : '/images/filipino-campus.jpg',
            alt: props.detail.identity?.status?.tone === 'positive'
                ? 'Filipino graduates celebrating an education milestone'
                : 'Students walking through a campus in the Philippines',
            focus: 'object-center',
        },
        history: {
            src: '/images/filipino-campus.jpg',
            alt: 'Students walking through a campus in the Philippines',
            focus: 'object-center',
        },
    };

    if (sectionVisuals[view]) {
        return sectionVisuals[view];
    }

    if (/(stem|science|technology|engineering)/.test(subject)) {
        return {
            src: '/images/filipino-students-science-class.jpg',
            alt: 'Filipino students participating in a science class',
            focus: 'object-center',
        };
    }

    if (/(college|university|tertiary|graduate)/.test(subject)) {
        return {
            src: '/images/filipino-graduates.jpg',
            alt: 'Filipino graduates celebrating an education milestone',
            focus: 'object-center',
        };
    }

    return {
        src: '/images/filipino-students-studying.jpg',
        alt: 'Filipino students studying together in a classroom',
        focus: 'object-center',
    };
});

function hideBrokenImage(event) {
    event.currentTarget.classList.add('hidden');
}
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <a href="/dashboard/applications" class="inline-flex min-h-8 items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                        <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to applications
                    </a>
                    <a v-if="detail.identity.conversation_href" :href="detail.identity.conversation_href" class="student-button student-button-secondary" data-message-provider>
                        <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>Message provider
                    </a>
                </div>

                <header class="mt-4 overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm" data-application-detail-header>
                    <div class="grid bg-portal-navy text-white lg:grid-cols-[minmax(0,1.65fr)_minmax(16rem,1fr)]">
                        <div class="relative z-10 flex min-w-0 items-start gap-4 px-5 py-5 sm:gap-5 sm:px-7 sm:py-7 lg:items-center">
                            <span class="relative grid size-16 shrink-0 place-items-center overflow-hidden rounded-md border border-white/20 bg-white p-2 shadow-sm sm:size-20 sm:p-2.5" data-application-logo-frame>
                                <i class="fa-solid fa-graduation-cap text-lg text-slate-500" aria-hidden="true"></i>
                                <img
                                    :src="detail.identity.program.logo_url || detail.identity.program.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="`${detail.identity.program.title} program logo`"
                                    class="absolute inset-0 size-full bg-white object-contain p-2 sm:p-2.5"
                                    data-application-program-logo
                                    @error="hideBrokenImage"
                                >
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Application record</p>
                                    <span :class="['student-badge', statusClass]" data-application-status>{{ detail.identity.status.label }}</span>
                                </div>

                                <h1 class="mt-2 break-words font-display text-xl font-bold leading-tight text-white sm:text-3xl lg:text-[2rem]">
                                    {{ detail.identity.program.title }}
                                </h1>

                                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-slate-200">
                                    <a
                                        v-if="detail.identity.provider.href"
                                        :href="detail.identity.provider.href"
                                        class="break-words font-semibold text-white underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-300"
                                    >
                                        {{ detail.identity.provider.name }}
                                    </a>
                                    <span v-else class="break-words font-semibold text-white">{{ detail.identity.provider.name }}</span>
                                    <span v-if="detail.identity.provider.verified" class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-200">
                                        <i class="fa-solid fa-circle-check block w-3 text-center text-xs leading-none" aria-hidden="true"></i>
                                        Verified provider
                                    </span>
                                </div>

                                <p v-if="detail.identity.submitted_at" class="mt-4 flex items-start gap-1.5 text-xs leading-5 text-slate-300"><i class="fa-regular fa-clock mt-1 block w-3 shrink-0 text-center leading-none" aria-hidden="true"></i><span>Submitted {{ detail.identity.submitted_at }}</span></p>
                            </div>
                        </div>

                        <figure class="relative hidden min-h-48 overflow-hidden bg-slate-800 lg:block" data-application-cover>
                            <img
                                :src="applicationVisual.src"
                                :alt="applicationVisual.alt"
                                :class="['absolute inset-0 h-full w-full object-cover', applicationVisual.focus]"
                            >
                            <div class="pointer-events-none absolute inset-0 bg-linear-to-r from-portal-navy via-portal-navy/15 to-transparent" aria-hidden="true"></div>
                        </figure>
                    </div>

                    <dl v-if="detail.snapshot?.length" class="grid grid-cols-2 border-t border-slate-200 bg-white xl:grid-cols-4" data-record-snapshot>
                        <div
                            v-for="(item, index) in detail.snapshot"
                            :key="item.key"
                            :class="[
                                'min-w-0 border-slate-200 px-4 py-4 sm:px-6',
                                index % 2 ? 'border-l' : '',
                                index >= 2 ? 'border-t xl:border-l xl:border-t-0' : '',
                            ]"
                        >
                            <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i :class="[snapshotIcons[item.key] || 'fa-solid fa-circle-info', 'block w-3 shrink-0 text-center text-xs leading-none text-slate-600']" aria-hidden="true"></i>{{ item.label }}</dt>
                            <dd class="mt-2 break-words text-sm font-bold leading-6 text-slate-950">{{ item.value }}</dd>
                        </div>
                    </dl>
                </header>

                <ApplicationDecisionBanner
                    v-if="detail.view === 'summary'"
                    class="mt-4 rounded-md"
                    :decision="detail.decision"
                />

                <ApplicantRecordTabs :items="detail.navigation" label="Application record" data-application-detail-navigation />

                <section v-if="detail.view !== 'summary'" class="relative mt-4 overflow-hidden rounded-md border border-slate-300 bg-white px-4 py-3 shadow-sm sm:px-5" aria-labelledby="application-section-title" data-record-section-intro>
                    <span class="absolute inset-y-0 left-0 w-1 bg-amber-400" aria-hidden="true"></span>
                    <h2 id="application-section-title" class="font-display text-lg font-bold text-slate-950 sm:text-xl">{{ detail.page.title }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ detail.page.description }}</p>
                </section>

                <slot></slot>
            </div>
        </section>
    </main>
</template>
