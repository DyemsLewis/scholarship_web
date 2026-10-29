<script setup>
import { ref, watch } from 'vue';
import { usePageScrollLock } from '../../composables/usePageScrollLock';
import { showPortalToast } from '../../support/portalToast';

const props = defineProps({
    open: { type: Boolean, default: false },
    release: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
usePageScrollLock(() => props.open);
const isSaving = ref(false);
const today = new Date().toISOString().slice(0, 10);
const form = ref(defaultForm());

function defaultForm() {
    return {
        response_type: 'confirmed',
        received_on: today,
        recipient_note: '',
        issue_type: 'not_received',
        issue_details: '',
        evidence: null,
        acknowledged: false,
    };
}

watch(() => props.open, (open) => {
    if (open) form.value = defaultForm();
}, { immediate: true });


function close() {
    if (!isSaving.value) emit('close');
}

async function submit() {
    if (!props.release || isSaving.value) return;
    if (!form.value.acknowledged) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that your response is accurate.',
        });
        return;
    }

    isSaving.value = true;
    const data = new FormData();
    data.append('response_type', form.value.response_type);
    data.append('acknowledged', '1');
    if (form.value.response_type === 'confirmed') {
        data.append('received_on', form.value.received_on);
        if (form.value.recipient_note) data.append('recipient_note', form.value.recipient_note);
    } else {
        data.append('issue_type', form.value.issue_type);
        data.append('issue_details', form.value.issue_details);
        if (form.value.evidence) data.append('evidence', form.value.evidence);
    }

    try {
        const response = await window.axios.post(
            `/dashboard/benefit-release-records/${props.release.record_id}/response`,
            data,
        );
        showPortalToast({ type: 'success', title: 'Response recorded', message: response.data.message });
        emit('saved');
        emit('close');
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Response not saved',
            message: errors
                ? Object.values(errors).flat()[0]
                : (error.response?.data?.message ?? 'Unable to record your response.'),
        });
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2200] grid place-items-center bg-slate-950/65 p-3 sm:p-5"
            role="dialog"
            aria-modal="true"
            aria-labelledby="benefit-response-title"
            @click.self="close"
            @keydown.esc="close"
        >
            <form class="flex max-h-[94vh] w-full max-w-xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl" @submit.prevent="submit">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Benefit receipt</p>
                        <h2 id="benefit-response-title" class="mt-1 text-xl font-bold">Confirm what you received</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ release?.title }}</p>
                    </div>
                    <button type="button" :disabled="isSaving" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-500 hover:text-slate-950" aria-label="Close response" @click="close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-5">
                    <fieldset>
                        <legend class="text-xs font-bold text-slate-700">Your response</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label :class="['cursor-pointer border bg-white p-3', form.response_type === 'confirmed' ? 'border-emerald-600 ring-1 ring-emerald-600' : 'border-slate-200']">
                                <input v-model="form.response_type" type="radio" value="confirmed" class="sr-only">
                                <span class="block text-sm font-bold">Received as listed</span>
                                <span class="mt-1 block text-xs text-slate-500">Confirm the benefit and date.</span>
                            </label>
                            <label :class="['cursor-pointer border bg-white p-3', form.response_type === 'issue' ? 'border-rose-500 ring-1 ring-rose-500' : 'border-slate-200']">
                                <input v-model="form.response_type" type="radio" value="issue" class="sr-only">
                                <span class="block text-sm font-bold">Report a problem</span>
                                <span class="mt-1 block text-xs text-slate-500">Tell the provider what was wrong.</span>
                            </label>
                        </div>
                    </fieldset>

                    <template v-if="form.response_type === 'confirmed'">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">Date received</span>
                            <input v-model="form.received_on" type="date" :max="today" required class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm">
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">Note <span class="font-normal text-slate-400">(optional)</span></span>
                            <textarea v-model="form.recipient_note" maxlength="1000" rows="3" placeholder="Add a short note only if needed." class="w-full resize-y border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea>
                        </label>
                    </template>

                    <template v-else>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">Problem</span>
                            <select v-model="form.issue_type" required class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm">
                                <option value="not_received">Benefit not received</option>
                                <option value="incorrect_amount">Incorrect amount</option>
                                <option value="incomplete_benefit">Benefit was incomplete</option>
                                <option value="damaged_item">Item was damaged</option>
                                <option value="other">Other problem</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">What happened</span>
                            <textarea v-model="form.issue_details" required minlength="5" maxlength="1500" rows="4" placeholder="Describe what was missing or different." class="w-full resize-y border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea>
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">Evidence <span class="font-normal text-slate-400">(optional)</span></span>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 file:mr-3 file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-bold" @change="form.evidence = $event.target.files?.[0] ?? null">
                        </label>
                    </template>

                    <label class="flex items-start gap-3 border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-700">
                        <input v-model="form.acknowledged" type="checkbox" class="mt-1 h-4 w-4 border-slate-300 text-slate-950">
                        <span>I confirm this response is accurate.</span>
                    </label>
                </div>

                <footer class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" :disabled="isSaving" class="border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="close">Cancel</button>
                    <button type="submit" :disabled="isSaving || !form.acknowledged" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSaving ? 'Saving...' : 'Submit response' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
