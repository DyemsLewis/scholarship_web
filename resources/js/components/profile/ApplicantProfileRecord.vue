<script setup>
defineProps({
    record: {
        type: Object,
        required: true,
    },
    sections: {
        type: Array,
        default: () => [],
    },
    verificationStatus: {
        type: String,
        default: 'unsubmitted',
    },
    verificationLabel: {
        type: String,
        default: 'Not verified',
    },
    editable: {
        type: Boolean,
        default: false,
    },
    primary: {
        type: Boolean,
        default: false,
    },
    showTrustNotice: {
        type: Boolean,
        default: true,
    },
    showDataSourceNotice: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['edit']);

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'pending') {
        return 'bg-amber-100 text-amber-900';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function hasValue(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
}
</script>

<template>
    <section :class="['overflow-hidden border border-slate-300 bg-white', primary ? 'rounded-md border-t-4 border-t-amber-400 shadow-sm' : 'shadow-sm']">
        <slot name="header">
        <header class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-lg font-bold text-slate-500">
                    <img v-if="record.photoUrl" :src="record.photoUrl" :alt="`${record.name} applicant photo`" class="h-full w-full object-cover">
                    <span v-else>{{ record.initials || 'AP' }}</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Applicant record</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 :class="['truncate font-bold text-slate-950', primary ? 'text-2xl' : 'text-xl']">{{ record.name }}</h2>
                        <span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(verificationStatus)]">
                            {{ verificationLabel }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm font-semibold text-slate-600">{{ record.educationSummary || 'Learning details not provided' }}</p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        </slot>

        <div v-if="showTrustNotice && verificationStatus !== 'approved'" class="flex items-start gap-3 border-b border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-5 text-amber-950 sm:px-5">
            <i class="fa-solid fa-shield-halved mt-0.5 shrink-0 text-amber-700" aria-hidden="true"></i>
            <p><strong>Profile not yet verified.</strong> Providers may give self-declared details less weight until the supporting evidence has been reviewed.</p>
        </div>

        <div v-if="showDataSourceNotice" class="flex items-start gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-800">
                <i class="fa-solid fa-user-pen text-sm" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <p class="text-sm font-bold text-slate-950">Self-declared profile information</p>
                <p class="mt-0.5 text-xs leading-5 text-slate-600">The applicant entered these details. The verification status applies to reviewed supporting evidence and does not automatically confirm every profile field.</p>
            </div>
        </div>

        <slot name="details">
        <div class="divide-y divide-slate-200">
            <section v-for="section in sections" :key="section.id" class="px-4 py-5 sm:px-5">
                <header class="flex min-h-9 items-start justify-between gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-950 text-sm text-white">
                            <i :class="section.icon" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <h3 class="font-bold text-slate-950">{{ section.title }}</h3>
                            <p v-if="section.description" class="mt-1 text-xs leading-5 text-slate-500">{{ section.description }}</p>
                        </div>
                    </div>
                    <button
                        v-if="editable && section.editable !== false"
                        type="button"
                        class="inline-flex h-9 shrink-0 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-50"
                        @click="$emit('edit', section.id)"
                    >
                        <i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>
                        Edit
                    </button>
                </header>

                <dl class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-4">
                    <div
                        v-for="item in section.items"
                        :key="`${section.id}-${item.label}`"
                        :class="['min-w-0', item.wide ? 'sm:col-span-2 xl:col-span-4' : '']"
                    >
                        <dt class="text-xs font-semibold text-slate-500">{{ item.label }}</dt>
                        <dd :class="['mt-1 break-words text-sm font-bold leading-5', hasValue(item.value) ? 'text-slate-950' : 'text-slate-400']">
                            {{ hasValue(item.value) ? item.value : 'Not provided' }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        </slot>

        <slot name="footer" />
    </section>
</template>
