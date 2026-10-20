<script setup>
import { computed, reactive, watch } from 'vue';
import { formatServiceDateTime, localServiceDateTime, useProviderServiceWorkspace } from '../../../composables/useProviderServiceWorkspace.js';
import BillingServiceWorkspaceShell from './components/BillingServiceWorkspaceShell.vue';

const {
    purchaseId,
    purchase,
    workspace,
    isLoading,
    isSaving,
    errorMessage,
    successMessage,
    directoryUrl,
    runAction,
} = useProviderServiceWorkspace('meeting');

const meetingForm = reactive({ meeting_scheduled_for: '', meeting_mode: 'online', meeting_purpose: '' });
const canRequestMeeting = computed(() => purchase.value?.status === 'paid' && purchase.value?.fulfillment_status !== 'completed');
const minimumMeeting = computed(() => localServiceDateTime(new Date(Date.now() + 60 * 60 * 1000).toISOString()));

watch(purchase, (value) => {
    if (!value) return;
    meetingForm.meeting_scheduled_for = localServiceDateTime(value.meeting_scheduled_for);
    meetingForm.meeting_mode = value.meeting_mode ?? 'online';
    meetingForm.meeting_purpose = value.meeting_purpose ?? workspace.value.meetingPurpose;
}, { immediate: true });

async function requestMeeting() {
    await runAction(
        () => window.axios.post(`/provider/billing/${purchaseId}/meeting`, { ...meetingForm }),
        'Meeting request sent.',
    );
}
</script>

<template>
    <BillingServiceWorkspaceShell
        :purchase="purchase"
        :workspace="workspace"
        active-section="meeting"
        :loading="isLoading"
        :error-message="errorMessage"
        :success-message="successMessage"
        :directory-url="directoryUrl"
    >
        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
            <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">{{ workspace.meetingTitle }}</h2><p class="mt-1 text-xs text-slate-500">{{ workspace.meetingDescription }}</p></div></header>

            <dl v-if="purchase.meeting_scheduled_for" class="grid grid-cols-3 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50">
                <div class="px-6 py-4">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Requested time</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-900">{{ formatServiceDateTime(purchase.meeting_scheduled_for) }}</dd>
                </div>
                <div class="px-6 py-4">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Format</dt>
                    <dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_mode }}</dd>
                </div>
                <div class="px-6 py-4">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Status</dt>
                    <dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_status || 'Pending' }}</dd>
                </div>
            </dl>

            <div v-if="purchase.meeting_admin_note" class="border-b border-slate-200 px-6 py-4 text-sm leading-6 text-slate-600">
                <span class="font-bold text-slate-900">Platform note:</span> {{ purchase.meeting_admin_note }}
            </div>

            <div v-if="purchase.meeting_status === 'confirmed' && purchase.meeting_join_url" class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4">
                <div><p class="text-sm font-bold text-slate-950">Online meeting ready</p><p class="mt-1 text-xs text-slate-500">Use the confirmed link at the scheduled time.</p></div>
                <a :href="purchase.meeting_join_url" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white"><i class="fa-solid fa-video" aria-hidden="true"></i>Join meeting</a>
            </div>

            <form v-if="canRequestMeeting" class="px-6 py-5" @submit.prevent="requestMeeting">
                <div class="grid grid-cols-[1fr_15rem] gap-5">
                    <label class="text-sm font-bold text-slate-800">Preferred date and time<input v-model="meetingForm.meeting_scheduled_for" type="datetime-local" :min="minimumMeeting" required class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm outline-none focus:border-slate-950"></label>
                    <label class="text-sm font-bold text-slate-800">Format<select v-model="meetingForm.meeting_mode" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="online">Online</option><option value="onsite">On-site</option></select></label>
                </div>
                <label class="mt-5 block text-sm font-bold text-slate-800">What should the meeting cover?<textarea v-model="meetingForm.meeting_purpose" rows="4" minlength="10" maxlength="1000" required class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950"></textarea></label>
                <div class="mt-4 flex justify-end">
                    <button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-calendar-plus']" aria-hidden="true"></i>{{ purchase.meeting_scheduled_for ? 'Update request' : 'Request meeting' }}</button>
                </div>
            </form>
            <p v-else class="px-6 py-8 text-sm text-slate-500">Meeting requests are closed for this service.</p>
        </section>
    </BillingServiceWorkspaceShell>
</template>
