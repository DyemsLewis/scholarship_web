<script setup>
import { onUnmounted, ref, watch } from 'vue';
import { showPortalToast } from '../../support/portalToast';

const props = defineProps({
    open: { type: Boolean, default: false },
    applicationId: { type: [Number, String], required: true },
    record: { type: Object, default: null },
    requirement: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const isSaving = ref(false);
const form = ref(defaultForm());

function defaultForm() {
    return {
        request_type: 'extension',
        reason_category: 'illness',
        explanation: '',
        requested_due_at: '',
        supporting_record: null,
        confirmation: false,
    };
}

watch(() => props.open, (open) => {
    document.body.classList.toggle('overflow-hidden', open);
    if (open) {
        form.value = defaultForm();
        if (!props.requirement?.requires_file) form.value.request_type = 'exception';
    }
}, { immediate: true });

onUnmounted(() => document.body.classList.remove('overflow-hidden'));

function dateOffset(date, days) {
    if (!date) return '';
    const value = new Date(`${date}T00:00:00`);
    value.setDate(value.getDate() + days);
    return value.toISOString().slice(0, 10);
}

function close() {
    if (!isSaving.value) emit('close');
}

async function submit() {
    if (!props.requirement || isSaving.value) return;
    if (!form.value.confirmation) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that the request details are accurate.',
        });
        return;
    }

    isSaving.value = true;
    const data = new FormData();
    data.append('request_type', form.value.request_type);
    data.append('reason_category', form.value.reason_category);
    data.append('explanation', form.value.explanation);
    data.append('confirmation', '1');
    if (form.value.request_type === 'extension') data.append('requested_due_at', form.value.requested_due_at);
    if (form.value.supporting_record) data.append('supporting_record', form.value.supporting_record);

    try {
        const response = await window.axios.post(
            `/dashboard/applications/${props.applicationId}/monitoring-requirements/${props.requirement.id}/adjustment-request`,
            data,
        );
        showPortalToast({ type: 'success', title: 'Request sent', message: response.data.message });
        emit('saved');
        emit('close');
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Request not sent',
            message: errors
                ? Object.values(errors).flat()[0]
                : (error.response?.data?.message ?? 'Unable to send this request.'),
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
            aria-labelledby="monitoring-adjustment-title"
            @click.self="close"
            @keydown.esc="close"
        >
            <form class="flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl" @submit.prevent="submit">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Check-in support</p>
                        <h2 id="monitoring-adjustment-title" class="mt-1 text-xl font-bold">Request an adjustment</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ requirement?.title }}</p>
                    </div>
                    <button type="button" :disabled="isSaving" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-500 hover:text-slate-950" aria-label="Close request" @click="close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-5">
                    <fieldset>
                        <legend class="text-xs font-bold text-slate-700">What do you need?</legend>
                        <div :class="['mt-2 grid gap-2', requirement?.requires_file ? 'sm:grid-cols-2' : 'grid-cols-1']">
                            <label v-if="requirement?.requires_file" :class="['cursor-pointer border bg-white p-3', form.request_type === 'extension' ? 'border-slate-950 ring-1 ring-slate-950' : 'border-slate-200']">
                                <input v-model="form.request_type" type="radio" value="extension" class="sr-only">
                                <span class="block text-sm font-bold">More time</span>
                                <span class="mt-1 block text-xs text-slate-500">Ask for a later deadline.</span>
                            </label>
                            <label :class="['cursor-pointer border bg-white p-3', form.request_type === 'exception' ? 'border-slate-950 ring-1 ring-slate-950' : 'border-slate-200']">
                                <input v-model="form.request_type" type="radio" value="exception" class="sr-only">
                                <span class="block text-sm font-bold">Exception</span>
                                <span class="mt-1 block text-xs text-slate-500">Ask the provider to excuse this item.</span>
                            </label>
                        </div>
                    </fieldset>

                    <label class="block">
                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Reason</span>
                        <select v-model="form.reason_category" required class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm">
                            <option value="illness">Illness or health</option>
                            <option value="family_emergency">Family emergency</option>
                            <option value="school_schedule">School schedule or record delay</option>
                            <option value="transfer">School transfer</option>
                            <option value="technical_issue">Technical issue</option>
                            <option value="other">Other circumstance</option>
                        </select>
                    </label>

                    <label v-if="form.request_type === 'extension'" class="block">
                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Requested deadline</span>
                        <input v-model="form.requested_due_at" type="date" required :min="dateOffset(record?.due_at, 1)" :max="dateOffset(record?.due_at, 60)" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Explanation</span>
                        <textarea v-model="form.explanation" required minlength="10" maxlength="2000" rows="4" placeholder="Explain what happened and how it affects this requirement." class="w-full resize-y border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea>
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Supporting record <span class="font-normal text-slate-400">(optional)</span></span>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 file:mr-3 file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-bold" @change="form.supporting_record = $event.target.files?.[0] ?? null">
                    </label>

                    <label class="flex items-start gap-3 border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-700">
                        <input v-model="form.confirmation" type="checkbox" class="mt-1 h-4 w-4 border-slate-300 text-slate-950">
                        <span>I confirm that this request is accurate and understand that the provider will decide it.</span>
                    </label>
                </div>

                <footer class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" :disabled="isSaving" class="border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="close">Cancel</button>
                    <button type="submit" :disabled="isSaving" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isSaving ? 'Sending...' : 'Send request' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
