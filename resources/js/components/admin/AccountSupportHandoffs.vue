<script setup>
import { computed, ref } from 'vue';
import AdminDialog from '../../pages/admin/components/AdminDialog.vue';

const props = defineProps({
    report: { type: Object, default: null },
    accountId: { type: [Number, String], default: null },
    handoffs: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['updated', 'busy']);
const activeDialog = ref('');
const selected = ref(null);
const task = ref('sign_in');
const note = ref('');
const busy = ref(false);
const error = ref('');
const feedback = ref('');
const pending = computed(() => props.handoffs.filter((item) => item.status === 'pending'));
const history = computed(() => props.handoffs.filter((item) => item.status !== 'pending'));
const canRefer = computed(() => props.report?.can_refer_account && !pending.value.length);
const valid = computed(() => note.value.trim().length >= 10 && note.value.trim().length <= (activeDialog.value === 'refer' ? 600 : 1000));
const title = computed(() => ({ refer: 'Send an account access task', complete: 'Return result to Support', cancel: 'Cancel account task' }[activeDialog.value] || 'Account task'));

function open(action, item = null) {
    if (busy.value || props.disabled) return;
    if (action === 'refer' ? !canRefer.value : item?.status !== 'pending') return;
    activeDialog.value = action;
    selected.value = item;
    note.value = '';
    task.value = props.report?.handoff_tasks?.[0]?.value || 'sign_in';
    error.value = '';
}

function close() {
    if (!busy.value) activeDialog.value = '';
}

async function submit() {
    if (busy.value || props.disabled || !activeDialog.value || !valid.value) return;
    const action = activeDialog.value;
    if (action === 'refer' ? !canRefer.value : selected.value?.status !== 'pending') return;
    busy.value = true;
    emit('busy', true);
    error.value = '';
    feedback.value = '';
    try {
        const response = action === 'refer'
            ? await window.axios.post(`/admin/workspaces/support/cases/${props.report.id}/account-handoffs`, {
                task: task.value, instruction: note.value.trim(), expected_support_token: props.report.support_token,
            }, { portalToast: false })
            : await window.axios.patch(action === 'cancel'
                ? `/admin/workspaces/support/cases/${props.report.id}/account-handoffs/${selected.value.id}/cancel`
                : `/admin/workspaces/accounts/${props.accountId}/support-handoffs/${selected.value.id}`, {
                result: note.value.trim(), expected_handoff_token: selected.value.token,
            }, { portalToast: false });
        emit('updated', response.data);
        feedback.value = response.data.message;
        activeDialog.value = '';
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors || {})[0]?.[0]
            || failure.response?.data?.message || 'The account task could not be updated. Your note has been kept.';
    } finally {
        busy.value = false;
        emit('busy', false);
    }
}
</script>

<template>
    <section v-if="report?.can_refer_account || handoffs.length" class="admin-panel mt-4 overflow-hidden" data-account-handoffs>
        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
            <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div><h2 class="text-base font-black text-slate-950">{{ report ? 'Account access handoff' : 'Referred by Support' }}</h2><p class="mt-0.5 text-sm text-slate-500">{{ report ? 'Account Manager checks access. Support keeps responsibility for the final reply.' : 'Check the request using the sign-in controls below, then return a result to Support.' }}</p></div></div>
            <button v-if="canRefer" type="button" :disabled="busy || disabled" class="h-10 shrink-0 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-50" @click="open('refer')">Send to Account Manager</button>
        </header>
        <div class="divide-y divide-slate-200">
            <div v-for="item in pending" :key="item.id" class="px-6 py-4">
                <div class="flex items-center justify-between gap-5"><div><h3 class="text-sm font-bold text-slate-950">{{ item.task_label }}</h3><p class="mt-1 text-xs text-slate-500">{{ item.status_label }} · {{ item.requested_at }}</p></div><button type="button" :disabled="busy || disabled" class="h-10 shrink-0 rounded-sm border border-slate-300 px-4 text-sm font-bold disabled:opacity-50" @click="open(report ? 'cancel' : 'complete', item)">{{ report ? 'Cancel task' : 'Return result' }}</button></div>
                <p class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-slate-700 [overflow-wrap:anywhere]">{{ item.instruction }}</p>
            </div>
            <p v-if="!pending.length && !history.length" class="px-6 py-4 text-sm text-slate-500">Use this only for sign-in, email, password, or account restrictions. Profile and program verification belong to the Review Officer.</p>
            <details v-if="history.length" class="px-6 py-4" :open="!pending.length">
                <summary class="cursor-pointer text-sm font-bold text-slate-700">Returned tasks and history ({{ history.length }})</summary>
                <div v-for="item in history" :key="item.id" class="mt-4 border-l-2 border-slate-200 pl-4"><p class="text-sm font-bold text-slate-900">{{ item.task_label }} <span class="ml-2 text-xs font-semibold text-slate-500">{{ item.status_label }}</span></p><p class="mt-1 whitespace-pre-line break-words text-sm text-slate-600 [overflow-wrap:anywhere]">{{ item.result }}</p><p class="mt-1 text-xs text-slate-500">{{ item.completed_at }} · Internal team note, not a reply to the reporter</p></div>
            </details>
        </div>
        <p v-if="feedback" class="border-t border-slate-200 bg-slate-50 px-6 py-3 text-sm font-semibold text-slate-700" role="status">{{ feedback }}</p>
    </section>
    <AdminDialog :open="Boolean(activeDialog)" :title="title" eyebrow="Internal handoff" :description="activeDialog === 'refer' ? 'Send only the access task. The full report and attachments are not shared. Never include passwords or sensitive personal data.' : activeDialog === 'cancel' ? 'Explain why the task is no longer needed. Support will continue handling the case.' : 'Record what you checked and any remaining account-holder step. This does not close the support case or approve the profile.'" :confirm-label="activeDialog === 'refer' ? 'Send task' : activeDialog === 'cancel' ? 'Cancel task' : 'Return result'" :busy="busy" :error="error" :confirm-disabled="!valid || disabled" @confirm="submit" @cancel="close">
        <label v-if="activeDialog === 'refer'" class="block text-sm font-bold text-slate-800">Access task<select v-model="task" class="mt-2 h-11 w-full rounded-sm border border-slate-300 bg-white px-3 text-sm"><option v-for="option in report.handoff_tasks" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
        <label class="block text-sm font-bold text-slate-800">{{ activeDialog === 'refer' ? 'Short instruction for Account Manager' : activeDialog === 'cancel' ? 'Cancellation reason' : 'Result for Support' }}<textarea v-model="note" rows="4" minlength="10" :maxlength="activeDialog === 'refer' ? 600 : 1000" class="mt-2 w-full rounded-sm border border-slate-300 px-3 py-2.5 text-sm font-normal" required></textarea></label>
        <p class="text-xs text-slate-500">At least 10 characters. Internal team notes are not shown to the reporter.</p>
    </AdminDialog>
</template>
