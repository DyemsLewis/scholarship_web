<script setup>
defineProps({
    conditions: {
        type: Array,
        default: () => [],
    },
    audience: {
        type: String,
        default: 'applicant',
    },
});

function conditionStatus(condition) {
    if (condition?.status) {
        return condition.status;
    }

    return {
        automatic: 'automatic',
        applicant_declaration: 'confirmation_required',
        provider_verification: 'provider_review',
        information: 'information',
    }[condition?.verification_type] ?? 'provider_review';
}

function statusMeta(condition) {
    const statuses = {
        pass: {
            label: 'Matched automatically',
            icon: 'fa-solid fa-check',
            iconClass: 'bg-emerald-100 text-emerald-700',
            badgeClass: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        },
        fail: {
            label: 'Does not match',
            icon: 'fa-solid fa-xmark',
            iconClass: 'bg-rose-100 text-rose-700',
            badgeClass: 'bg-rose-50 text-rose-800 ring-rose-200',
        },
        missing: {
            label: 'Profile information needed',
            icon: 'fa-solid fa-exclamation',
            iconClass: 'bg-amber-100 text-amber-800',
            badgeClass: 'bg-amber-50 text-amber-900 ring-amber-200',
        },
        automatic: {
            label: 'Checked by DSS',
            icon: 'fa-solid fa-bolt',
            iconClass: 'bg-slate-100 text-slate-700',
            badgeClass: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        confirmation_required: {
            label: 'Applicant confirmation',
            icon: 'fa-solid fa-user-check',
            iconClass: 'bg-amber-100 text-amber-800',
            badgeClass: 'bg-amber-50 text-amber-900 ring-amber-200',
        },
        provider_review: {
            label: 'Provider review',
            icon: 'fa-solid fa-shield-halved',
            iconClass: 'bg-slate-100 text-slate-700',
            badgeClass: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        information: {
            label: 'Information only',
            icon: 'fa-solid fa-circle-info',
            iconClass: 'bg-slate-100 text-slate-600',
            badgeClass: 'bg-slate-100 text-slate-600 ring-slate-200',
        },
    };

    return statuses[conditionStatus(condition)] ?? statuses.provider_review;
}

function conditionNote(condition, audience) {
    if (condition?.note) {
        return condition.note;
    }

    const status = conditionStatus(condition);

    if (status === 'automatic') {
        return 'The portal compares this with the applicant profile.';
    }

    if (status === 'confirmation_required') {
        return audience === 'applicant'
            ? 'Review this carefully before submitting your application.'
            : 'The applicant must confirm this condition when applying.';
    }

    if (status === 'information') {
        return 'This explains the program and does not affect the profile match.';
    }

    return audience === 'applicant'
        ? 'The provider checks this during pre-screening.'
        : 'Confirm this condition using the applicant record and submitted evidence.';
}
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
        <article
            v-for="condition in conditions"
            :key="`${condition.key}-${condition.statement}`"
            class="flex flex-col gap-3 border-b border-slate-200 p-3.5 last:border-b-0 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', statusMeta(condition).iconClass]">
                    <i :class="statusMeta(condition).icon" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-950">{{ condition.label || 'Eligibility condition' }}</p>
                    <p class="mt-0.5 text-sm leading-5 text-slate-700">{{ condition.statement }}</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ conditionNote(condition, audience) }}</p>
                </div>
            </div>
            <span :class="['w-fit shrink-0 rounded px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1', statusMeta(condition).badgeClass]">
                {{ statusMeta(condition).label }}
            </span>
        </article>
    </div>
</template>
