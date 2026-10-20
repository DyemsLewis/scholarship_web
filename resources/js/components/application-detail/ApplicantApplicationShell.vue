<script setup>
import { computed } from 'vue';
import ApplicantSidebar from '../ApplicantSidebar.vue';
import ApplicationDecisionBanner from './ApplicationDecisionBanner.vue';

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
});

const statusClass = computed(() => ({
    positive: 'bg-emerald-100 text-emerald-800',
    negative: 'bg-rose-100 text-rose-800',
    attention: 'bg-amber-100 text-amber-800',
    muted: 'bg-slate-100 text-slate-600',
    active: 'bg-sky-100 text-sky-800',
    neutral: 'bg-slate-100 text-slate-700',
}[props.detail.identity?.status?.tone] ?? 'bg-slate-100 text-slate-700'));

function hideBrokenImage(event) {
    event.currentTarget.classList.add('hidden');
}
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <a
                    href="/dashboard/applications"
                    class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950"
                >
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to applications
                </a>

                <header class="mt-5 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="flex min-w-0 items-center gap-4">
                            <div class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-md border border-slate-200 bg-slate-50 sm:h-16 sm:w-16">
                                <i class="fa-solid fa-graduation-cap text-lg text-slate-400" aria-hidden="true"></i>
                                <img
                                    :src="detail.identity.program.image_url"
                                    :alt="`${detail.identity.program.title} image`"
                                    class="absolute inset-0 h-full w-full object-cover"
                                    @error="hideBrokenImage"
                                >
                            </div>

                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Application record</p>
                                <h1 class="mt-1 font-display text-xl font-bold leading-tight text-slate-950 sm:text-2xl">
                                    {{ detail.identity.program.title }}
                                </h1>
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600">
                                    <a
                                        v-if="detail.identity.provider.href"
                                        :href="detail.identity.provider.href"
                                        class="font-semibold hover:text-slate-950 hover:underline"
                                    >
                                        {{ detail.identity.provider.name }}
                                    </a>
                                    <span v-else class="font-semibold">{{ detail.identity.provider.name }}</span>
                                    <span v-if="detail.identity.provider.verified" class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
                                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                        Verified
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-3 sm:flex-col sm:items-end">
                            <span :class="['inline-flex rounded px-2.5 py-1.5 text-xs font-bold uppercase tracking-wide', statusClass]">
                                {{ detail.identity.status.label }}
                            </span>
                            <p v-if="detail.identity.submitted_at" class="text-xs text-slate-500">
                                Submitted {{ detail.identity.submitted_at }}
                            </p>
                        </div>
                    </div>

                    <dl class="grid border-t border-slate-200 bg-slate-50/80 sm:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="(item, index) in detail.snapshot"
                            :key="item.key"
                            :class="[
                                'px-4 py-3 sm:px-5',
                                index > 0 ? 'border-t border-slate-200 sm:border-t-0 sm:border-l' : '',
                                index === 2 ? 'sm:border-l-0 xl:border-l' : '',
                                index >= 2 ? 'sm:border-t xl:border-t-0' : '',
                            ]"
                        >
                            <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{{ item.label }}</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ item.value }}</dd>
                        </div>
                    </dl>
                </header>

                <ApplicationDecisionBanner class="mt-4 rounded-md" :decision="detail.decision" />

                <nav class="mt-4 overflow-x-auto rounded-md border border-slate-300 bg-white p-1 shadow-sm" aria-label="Application record">
                    <div class="grid min-w-[42rem] grid-cols-5 gap-1">
                        <a
                            v-for="item in detail.navigation"
                            :key="item.key"
                            :href="item.href"
                            :aria-current="item.active ? 'page' : undefined"
                            :class="[
                                'flex min-h-11 items-center justify-center gap-2 rounded px-3 py-2 text-sm font-bold transition',
                                item.active
                                    ? 'bg-slate-950 text-white'
                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                            ]"
                        >
                            <i :class="[item.icon, 'w-4 text-center text-xs']" aria-hidden="true"></i>
                            <span>{{ item.label }}</span>
                            <span
                                v-if="item.count"
                                :class="[
                                    'grid h-5 min-w-5 place-items-center rounded px-1 text-[10px]',
                                    item.active ? 'bg-amber-300 text-slate-950' : 'bg-amber-100 text-amber-800',
                                ]"
                            >
                                {{ item.count }}
                            </span>
                        </a>
                    </div>
                </nav>

                <slot></slot>
            </div>
        </section>
    </main>
</template>
