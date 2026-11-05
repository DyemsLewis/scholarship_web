import { computed, ref } from 'vue';
import { reviewErrorMessage } from '../support/adminReviewPresentation.js';

export function useApplicantEvidenceDecision({ document, allowed, submit, onSaved, extraPayload = () => ({}), extraValid = () => true }) {
    const outcome = ref('');
    const notes = ref('');
    const compared = ref(false);
    const saving = ref(false);
    const stale = ref(false);
    const saved = ref(false);
    const error = ref('');
    const canSave = computed(() => allowed() && !!document()?.review_url
        && Number.isInteger(document()?.revision) && document().revision > 0
        && !saving.value && !stale.value && !saved.value
        && (outcome.value === 'rejected' ? !!notes.value.trim()
            : outcome.value === 'approved' && compared.value && document()?.file_available !== false && extraValid()));

    function reset() {
        if (saving.value) return;
        outcome.value = ''; notes.value = ''; compared.value = false;
        stale.value = false; saved.value = false; error.value = '';
    }

    async function save() {
        if (!canSave.value) return;
        saving.value = true; error.value = '';
        const current = document();
        try {
            const response = await submit(current.review_url, {
                status: outcome.value, expected_revision: current.revision, review_notes: notes.value.trim() || null,
                ...(outcome.value === 'approved' ? extraPayload() : {}),
            }, { portalToast: false });
            saved.value = true;
            onSaved(response.data);
        } catch (failure) {
            stale.value = failure.response?.status === 409;
            error.value = stale.value ? 'This evidence changed while you were reviewing it. Reload the record and compare the current file before deciding.'
                : reviewErrorMessage(failure, 'The document decision could not be saved. Your note has been kept.');
        } finally { saving.value = false; }
    }

    return { outcome, notes, compared, saving, stale, saved, error, canSave, reset, save };
}
