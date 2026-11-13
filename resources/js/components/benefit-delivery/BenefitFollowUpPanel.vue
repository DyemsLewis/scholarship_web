<script setup>
import { computed, ref, useId } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { confirmOutcome } from '../../support/outcomeConfirmation.js';

const props = defineProps({ followUp: { type: Object, required: true }, context: { type: String, default: 'Benefit release' }, provider: Boolean });
const emit = defineEmits(['saved']);
const action = ref(null);
const note = ref('');
const respondBy = ref('');
const replacementId = ref('');
const error = ref('');
const busy = ref(false);
const dialog = ref(null);
const titleId = useId();
const { handleKeydown } = useModalAccessibility(() => !!action.value, dialog, close);
const requiresDate = computed(() => ['notice', 'remind'].includes(action.value));
const titles = { notice: 'Arrange the missed benefit', remind: 'Send a follow-up reminder', close_unclaimed: 'Record an unclaimed benefit', continue: 'Ask for a new arrangement', decline: 'Decline this benefit', link_replacement: 'Link a replacement release' };
const eventLabels = { notice: 'Portal notice sent', remind: 'Reminder sent', close_unclaimed: 'Recorded as unclaimed', continue: 'Recipient requested help', decline: 'Recipient declined the benefit', rescheduled: 'Replacement scheduled', link_replacement: 'Replacement linked' };
const minDate = computed(() => {
    const date = new Date();
    date.setDate(date.getDate() + (action.value === 'notice' ? 7 : 2));
    return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Manila' }).format(date);
});

function show(chosen) {
    action.value = chosen;
    note.value = '';
    replacementId.value = '';
    error.value = '';
    respondBy.value = props.followUp.respond_by && props.followUp.respond_by > minDate.value ? props.followUp.respond_by : minDate.value;
}
function close() {
    if (!busy.value) action.value = null;
}
function dateLabel(value) {
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date(value));
}
async function save() {
    if (busy.value || !action.value) return;
    const chosen = action.value;
    const input = { action: chosen, note: note.value.trim(), respond_by: requiresDate.value ? respondBy.value : null, replacement_record_id: chosen === 'link_replacement' ? replacementId.value : null, expected_revision: props.followUp.revision, confirmed: true };
    const url = props.followUp.action_url;
    busy.value = true;
    error.value = '';
    try {
        if (!await confirmOutcome({ title: titles[chosen], record: props.context, confirmLabel: chosen === 'decline' ? 'Decline benefit' : 'Confirm',
            consequence: chosen === 'close_unclaimed' ? 'This records an undelivered benefit, not confirmed receipt, voluntary withdrawal, or misconduct.'
                : chosen === 'decline' ? 'Only this benefit is declined. Your scholarship agreement and benefits already received stay unchanged.'
                    : chosen === 'link_replacement' ? 'The replacement will be linked to this missed release. Receipt still requires confirmation.'
                        : requiresDate.value ? `A portal notification will be sent. Reply deadline: ${input.respond_by}.` : 'The provider will be notified that you need a new arrangement.',
            danger: ['decline', 'close_unclaimed'].includes(chosen),
        }, () => action.value === chosen && props.followUp.revision === input.expected_revision)) return;
        await window.axios.post(url, input, { portalToast: false });
        action.value = null;
        emit('saved');
    } catch (failure) {
        const messages = failure.response?.data?.errors;
        error.value = messages ? Object.values(messages).flat()[0] : failure.response?.data?.message || 'The follow-up could not be saved. Please try again.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section data-benefit-follow-up class="mt-5 overflow-hidden rounded-sm border border-slate-300 bg-white">
        <header class="flex flex-wrap items-start justify-between gap-3 bg-slate-50 px-4 py-4 sm:px-5">
            <div class="flex min-w-0 items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-comment-dots text-sm" aria-hidden="true"></i></span><div><h3 class="text-base font-bold text-slate-950">Benefit follow-up</h3><p class="mt-1 text-sm text-slate-600">{{ followUp.label }}</p></div></div>
            <span v-if="followUp.deadline_label && followUp.status === 'awaiting_response'" class="rounded-sm border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-900">{{ followUp.deadline_passed ? 'Deadline passed:' : 'Reply by' }} {{ followUp.deadline_label }}</span>
        </header>
        <div class="space-y-3 border-t border-slate-200 px-4 py-4 text-sm sm:px-5">
            <p class="leading-6 text-slate-600">{{ followUp.instructions || (provider ? 'Send clear instructions and give the recipient at least seven days to reply.' : 'The provider has not sent follow-up instructions yet. Contact them if you need a new arrangement.') }}</p>
            <p v-if="followUp.recipient_note" class="leading-6 text-slate-700"><strong>Recipient reply:</strong> {{ followUp.recipient_note }}</p>
            <p v-if="followUp.resolution_note" class="leading-6 text-slate-700">{{ followUp.resolution_note }}</p>
            <p class="text-xs leading-5 text-slate-500">{{ provider ? 'An unclaimed benefit is not a receipt, withdrawal, or finding of misconduct.' : 'This follow-up does not change your scholarship status or count as a receipt.' }}</p>
            <p v-if="provider && followUp.status === 'awaiting_response'" class="text-xs leading-5 text-slate-500">Allow two days between contact attempts. Closing as unclaimed requires a reminder, two more days, an expired deadline, and no recipient reply.</p>
            <div class="flex flex-wrap gap-2">
                <button v-if="followUp.can_notice" type="button" class="min-h-10 rounded-sm bg-slate-950 px-4 font-bold text-white" @click="show('notice')">Send follow-up notice</button>
                <button v-if="followUp.can_remind" type="button" class="min-h-10 rounded-sm border border-slate-300 bg-white px-4 font-bold text-slate-800" @click="show('remind')">Send reminder</button>
                <button v-if="followUp.can_close" type="button" class="min-h-10 rounded-sm border border-slate-300 bg-white px-4 font-bold text-slate-800" @click="show('close_unclaimed')">Record as unclaimed</button>
                <a v-if="followUp.schedule_url" :href="followUp.schedule_url" class="inline-flex min-h-10 items-center rounded-sm border border-slate-300 bg-white px-4 font-bold text-slate-800">Schedule replacement</a>
                <button v-if="followUp.replacements?.length" type="button" class="min-h-10 rounded-sm border border-slate-300 bg-white px-4 font-bold text-slate-800" @click="show('link_replacement')">Link existing replacement</button>
                <button v-if="followUp.can_reply" type="button" class="min-h-10 rounded-sm bg-slate-950 px-4 font-bold text-white" @click="show('continue')">Ask for a new arrangement</button>
                <button v-if="followUp.can_reply && followUp.status !== 'declined'" type="button" class="min-h-10 rounded-sm border border-slate-300 bg-white px-4 font-bold text-slate-700" @click="show('decline')">Decline this benefit</button>
            </div>
            <details v-if="followUp.events.length" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer font-bold text-slate-700">Contact and response history</summary><ol class="mt-3 space-y-3"><li v-for="(event, index) in followUp.events" :key="index" class="border-l-2 border-slate-200 pl-3"><p class="text-xs font-bold text-slate-800">{{ eventLabels[event.action] || 'Follow-up updated' }} <span class="font-normal text-slate-500">{{ dateLabel(event.at) }}</span></p><p class="mt-1 text-xs leading-5 text-slate-600">{{ event.note }}</p></li></ol></details>
        </div>
    </section>
    <Teleport to="body">
        <div v-if="action" class="fixed inset-0 z-[2200] grid place-items-center bg-slate-950/65 p-4" @click.self="close">
            <section ref="dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1" data-benefit-follow-up-dialog class="flex max-h-[90dvh] w-full max-w-2xl flex-col overflow-hidden rounded-md bg-white shadow-2xl outline-none" @keydown="handleKeydown">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-5 sm:px-6"><div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">Benefit follow-up</p><h2 :id="titleId" class="mt-1 font-display text-xl font-bold text-slate-950">{{ titles[action] }}</h2><p class="mt-1 text-sm text-slate-500">{{ context }}</p></div><button type="button" :disabled="busy" aria-label="Close follow-up" class="grid h-9 w-9 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <form class="overflow-y-auto px-5 py-5 sm:px-6" @submit.prevent="save">
                    <p v-if="error" role="alert" class="mb-4 rounded-sm border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">{{ error }}</p>
                    <p class="mb-4 text-sm leading-6 text-slate-600">{{ action === 'close_unclaimed' ? 'Explain the unsuccessful follow-up. No receipt will be recorded and no scholarship withdrawal will be inferred.' : action === 'decline' ? 'Decline only this benefit. This does not cancel your scholarship or change what you already received.' : action === 'continue' ? 'Tell the provider how they can help arrange the missed benefit.' : action === 'link_replacement' ? 'Choose a later release for this recipient and the same benefit. The original missed attempt will remain in history.' : 'Explain how to arrange collection or correct delivery details. Do not ask for bank account numbers in this message; use the secure payout form.' }}</p>
                    <label v-if="action === 'link_replacement'" class="mb-4 block text-sm font-bold text-slate-700">Replacement release<select v-model="replacementId" required :disabled="busy" class="mt-2 block min-h-11 w-full rounded-sm border border-slate-300 bg-white px-3 font-normal"><option value="" disabled>Choose a release</option><option v-for="item in followUp.replacements" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
                    <label class="block text-sm font-bold text-slate-700">{{ provider ? 'Instructions / note' : 'Your message' }}<textarea v-model="note" required minlength="10" maxlength="2000" rows="4" :disabled="busy" class="mt-2 w-full rounded-sm border border-slate-300 px-3 py-2 font-normal text-slate-900 focus:border-slate-900 focus:outline-none"></textarea></label>
                    <label v-if="requiresDate" class="mt-4 block text-sm font-bold text-slate-700">Reply deadline<input v-model="respondBy" type="date" required :min="minDate" :disabled="busy" class="mt-2 block min-h-11 w-full rounded-sm border border-slate-300 px-3 font-normal text-slate-900"><span class="mt-1 block text-xs font-normal text-slate-500">{{ action === 'notice' ? 'Allow at least seven days for the first reply.' : 'Allow at least two more days. The existing deadline will not be shortened.' }}</span></label>
                    <footer class="mt-5 flex justify-end gap-2 border-t border-slate-200 pt-4"><button type="button" :disabled="busy" class="min-h-10 rounded-sm border border-slate-300 px-4 text-sm font-bold text-slate-700" @click="close">Cancel</button><button type="submit" :disabled="busy" class="min-h-10 rounded-sm bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-50">{{ busy ? 'Saving...' : 'Continue' }}</button></footer>
                </form>
            </section>
        </div>
    </Teleport>
</template>
