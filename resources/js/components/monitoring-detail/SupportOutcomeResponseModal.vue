<script setup>
import { ref, watch } from 'vue';
import { usePageScrollLock } from '../../composables/usePageScrollLock';
import { showPortalToast } from '../../support/portalToast';

const props = defineProps({
    open: { type: Boolean, default: false },
    outcome: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);
usePageScrollLock(() => props.open);
const isSaving = ref(false);
const form = ref(defaultForm());

function defaultForm() {
    return {
        response_type: 'acknowledged',
        message: '',
        attachment: null,
        confirmed: false,
    };
}

watch(() => props.open, (open) => {
    if (open) form.value = defaultForm();
}, { immediate: true });


function close() {
    if (!isSaving.value) emit('close');
}

async function submit() {
    if (!props.outcome || isSaving.value) return;
    if (!form.value.confirmed) {
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
    data.append('confirmed', '1');
    if (form.value.message) data.append('message', form.value.message);
    if (form.value.attachment) data.append('attachment', form.value.attachment);

    try {
        const response = await window.axios.post(
            `/dashboard/support-decisions/${props.outcome.id}/response`,
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
            aria-labelledby="support-outcome-response-title"
            @click.self="close"
            @keydown.esc="close"
        >
            <form class="flex max-h-[94vh] w-full max-w-xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl" @submit.prevent="submit">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Support outcome</p>
                        <h2 id="support-outcome-response-title" class="mt-1 text-xl font-bold">Respond to the decision</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ outcome?.decision_label }}</p>
                    </div>
                    <button type="button" :disabled="isSaving" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-500 hover:text-slate-950" aria-label="Close response" @click="close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-5">
                    <fieldset>
                        <legend class="text-xs font-bold text-slate-700">Choose a response</legend>
                        <div class="mt-2 space-y-2">
                            <label :class="['block cursor-pointer border bg-white p-3', form.response_type === 'acknowledged' ? 'border-emerald-600 ring-1 ring-emerald-600' : 'border-slate-200']">
                                <input v-model="form.response_type" type="radio" value="acknowledged" class="sr-only">
                                <span class="block text-sm font-bold">Acknowledge outcome</span>
                                <span class="mt-1 block text-xs text-slate-500">Confirm that you received and reviewed it.</span>
                            </label>
                            <label :class="['block cursor-pointer border bg-white p-3', form.response_type === 'clarification_requested' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-slate-200']">
                                <input v-model="form.response_type" type="radio" value="clarification_requested" class="sr-only">
                                <span class="block text-sm font-bold">Ask for clarification</span>
                                <span class="mt-1 block text-xs text-slate-500">Ask the provider to explain part of the decision.</span>
                            </label>
                            <label v-if="outcome?.decision === 'terminated'" :class="['block cursor-pointer border bg-white p-3', form.response_type === 'reconsideration_requested' ? 'border-rose-500 ring-1 ring-rose-500' : 'border-slate-200']">
                                <input v-model="form.response_type" type="radio" value="reconsideration_requested" class="sr-only">
                                <span class="block text-sm font-bold">Request reconsideration</span>
                                <span class="mt-1 block text-xs text-slate-500">Provide information the provider should review again.</span>
                            </label>
                        </div>
                    </fieldset>

                    <template v-if="form.response_type !== 'acknowledged'">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">Message</span>
                            <textarea v-model="form.message" required minlength="5" maxlength="1500" rows="4" placeholder="Explain what you need the provider to review." class="w-full resize-y border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea>
                        </label>
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-bold text-slate-700">Supporting file <span class="font-normal text-slate-400">(optional)</span></span>
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 file:mr-3 file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-bold" @change="form.attachment = $event.target.files?.[0] ?? null">
                        </label>
                    </template>

                    <label class="flex items-start gap-3 border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-700">
                        <input v-model="form.confirmed" type="checkbox" class="mt-1 h-4 w-4 border-slate-300 text-slate-950">
                        <span>I confirm this response is accurate.</span>
                    </label>
                </div>

                <footer class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" :disabled="isSaving" class="border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="close">Cancel</button>
                    <button type="submit" :disabled="isSaving || !form.confirmed" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSaving ? 'Saving...' : 'Submit response' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
