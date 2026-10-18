<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    decision: { type: Object, default: () => ({}) },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);
const reason = ref('');
const reasonInput = ref(null);

watch(() => props.open, (isOpen) => {
    if (!isOpen) return;
    reason.value = '';
    nextTick(() => reasonInput.value?.focus());
});

function submit() {
    const value = reason.value.trim();
    if (value.length < 10 || props.busy) return;
    emit('confirm', value);
}

function handleKeydown(event) {
    if (props.open && event.key === 'Escape' && !props.busy) emit('cancel');
}

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/60 p-4"
            role="presentation"
            @click.self="!busy && emit('cancel')"
        >
            <form class="w-full max-w-lg border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="decision-reopen-title" @submit.prevent="submit">
                <header class="border-b border-slate-200 px-6 py-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Decision correction</p>
                    <h2 id="decision-reopen-title" class="mt-1 text-xl font-bold text-slate-950">Reopen {{ decision.stage_label || 'latest decision' }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Return the application to this stage. The original result stays in history and the applicant will be notified.</p>
                </header>
                <div class="px-6 py-5">
                    <div class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        Current result: <strong>{{ decision.result_label || 'Recorded' }}</strong>
                    </div>
                    <label class="mt-5 block text-sm font-semibold text-slate-700">
                        Reason for reopening
                        <textarea
                            ref="reasonInput"
                            v-model="reason"
                            rows="4"
                            required
                            minlength="10"
                            maxlength="1000"
                            class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950"
                            placeholder="Explain the mistake or information that needs another review."
                        ></textarea>
                    </label>
                    <p class="mt-2 text-xs text-slate-500">This reason becomes part of the application audit history.</p>
                </div>
                <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" :disabled="busy" @click="emit('cancel')">Cancel</button>
                    <button type="submit" class="h-10 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50" :disabled="busy || reason.trim().length < 10">{{ busy ? 'Reopening...' : 'Reopen decision' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
