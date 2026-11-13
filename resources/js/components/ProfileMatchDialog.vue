<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { usePageScrollLock } from '../composables/usePageScrollLock';

const props = defineProps({ scholarship: { type: Object, default: null } });
const emit = defineEmits(['close']);
const dialog = ref(null);
const match = computed(() => props.scholarship?.eligibility_match ?? {});
const calculation = computed(() => match.value.match_calculation ?? {});
const criteria = computed(() => (match.value.criteria ?? []).filter(row => row.counts_toward_match));
usePageScrollLock(() => Boolean(props.scholarship));

watch(() => props.scholarship, async (scholarship) => {
    await nextTick();
    if (scholarship && !dialog.value?.open) dialog.value?.showModal();
    else if (!scholarship && dialog.value?.open) dialog.value.close();
});
</script>

<template>
    <Teleport to="body">
        <dialog ref="dialog" class="m-auto max-h-[calc(100dvh_-_2rem)] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-md border border-slate-300 bg-white p-0 text-slate-950 shadow-xl backdrop:bg-slate-950/60" aria-labelledby="profile-match-title" @cancel.prevent="emit('close')" @close="emit('close')" @click.self="emit('close')" data-profile-match-dialog>
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <div><h2 id="profile-match-title" class="text-lg font-bold">How your profile match is calculated</h2><p class="mt-1 text-sm text-slate-500">{{ scholarship?.title }}</p></div>
                <button autofocus type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded border border-slate-300 text-slate-600" aria-label="Close match explanation" @click="emit('close')"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div class="px-5 py-4">
                <div class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3">
                    <p v-if="calculation.compared" class="text-lg font-bold">{{ calculation.matched }} of {{ calculation.compared }} checks matched = {{ calculation.percentage }}%</p>
                    <p v-else class="text-sm font-semibold">No structured rules to compare</p>
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ calculation.formula }}</p>
                </div>
                <dl class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                    <div v-for="criterion in criteria" :key="criterion.key" class="flex items-center justify-between gap-4 py-3">
                        <dt class="text-sm font-semibold">{{ criterion.label }}<span v-if="criterion.rule_mode === 'preferred'" class="ml-2 text-xs font-normal text-slate-500">Preference</span></dt>
                        <dd :class="['shrink-0 text-xs font-bold', criterion.status === 'pass' ? 'text-emerald-800' : 'text-slate-600']">{{ criterion.status === 'pass' ? 'Matched' : criterion.needs_review ? 'Needs review' : criterion.status === 'missing' ? 'Information needed' : 'Not matched' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs leading-5 text-slate-600">Each compared check counts equally; rules open to everyone are excluded. Missing or unconfirmed information is not counted as a match.<span v-if="calculation.includes_document_readiness"> Prepared documents are included because this program requires files before applying.</span></p>
                <p class="mt-3 text-sm leading-6 text-slate-700">{{ calculation.notice }}</p>
            </div>
            <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-3"><a v-if="scholarship" :href="`/dashboard/scholarships/${scholarship.id}/eligibility#scholarship-section`" class="rounded bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">View eligibility comparison<i class="fa-solid fa-arrow-right ml-2 text-xs" aria-hidden="true"></i></a></footer>
        </dialog>
    </Teleport>
</template>
