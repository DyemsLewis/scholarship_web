import { computed, ref } from 'vue';
import { withdrawalError } from '../support/recipientWithdrawal.js';

export function useWithdrawalAction({ mode, record, endpoint, send, onSaved }) {
    const notes = ref('');
    const outcome = ref('');
    const confirmed = ref(false);
    const saving = ref(false);
    const stale = ref(false);
    const saved = ref(false);
    const error = ref('');
    const canSave = computed(() => {
        if (saving.value || stale.value || saved.value || !confirmed.value) return false;
        const current = record();
        const validRevision = Number.isInteger(current?.revision) && current.revision > 0;
        const validNote = notes.value.trim().length >= 10 && notes.value.trim().length <= 2000;
        if (mode() === 'create') return !!endpoint() && validNote;
        if (current?.status !== 'pending' || !validRevision) return false;
        if (mode() === 'cancel') return current.can_cancel === true && !!current.cancel_url;
        return mode() === 'review' && current.can_review === true && !!current.review_url
            && ['approved', 'declined'].includes(outcome.value) && validNote;
    });
    function reset() {
        if (saving.value) return;
        notes.value = ''; outcome.value = ''; confirmed.value = false;
        stale.value = false; saved.value = false; error.value = '';
    }
    function refreshed() {
        confirmed.value = false; stale.value = false; error.value = '';
    }
    async function save() {
        if (!canSave.value) return;
        saving.value = true; error.value = '';
        const action = mode();
        const current = record();
        const body = action === 'create' ? { reason: notes.value.trim(), confirmed: true }
            : { expected_revision: current.revision, confirmed: true,
                ...(action === 'review' ? { outcome: outcome.value, review_note: notes.value.trim() } : {}) };
        try {
            const response = await send(action === 'create' ? 'post' : 'patch',
                action === 'create' ? endpoint() : action === 'cancel' ? current.cancel_url : current.review_url,
                body, { portalToast: false });
            saved.value = true;
            onSaved(response.data);
        } catch (failure) {
            stale.value = failure.response?.status === 409 || failure.response?.status === 403
                || (action === 'review' && !!failure.response?.data?.errors?.withdrawal);
            error.value = stale.value
                ? 'This request or your access changed. Reload the current record before continuing. Your explanation has been kept.'
                : withdrawalError(failure);
        } finally { saving.value = false; }
    }
    return { notes, outcome, confirmed, saving, stale, saved, error, canSave, reset, refreshed, save };
}
