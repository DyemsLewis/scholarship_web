import { createApp, h, reactive } from 'vue';
import '../../resources/css/app.css';
import OutcomeConfirmationHost from '../../resources/js/components/OutcomeConfirmationHost.vue';
import ProgramReviewRecordPage from '../../resources/js/pages/admin/reviews/ProgramReviewRecordPage.vue';
import RecipientAgreementDialog from '../../resources/js/components/application-detail/RecipientAgreementDialog.vue';
import DecisionReopenDialog from '../../resources/js/components/DecisionReopenDialog.vue';
import { installModalScrollEnvironment } from '../../resources/js/support/modalScrollEnvironment.js';

const state = reactive({ writes: [], agreementOpen: false, reopenOpen: false, termsAccepted: false, note: '' });
const program = { id: 4, title: 'Learning Grant', provider: 'Education Foundation', status: 'pending_review', description: 'Support for enrolled learners.', requirements: ['Current grades', 'Enrollment certificate'], slots_available: 20 };
window.fixture = state;
window.portalPathname = () => '/admin/workspaces/reviews/programs/4';
window.portalUser = { name: 'Review officer', admin_role: 'review_officer', permissions: [] };
window.axios = {
    get: async () => ({ data: { scholarship: { ...program } } }),
    patch: async (url, payload) => {
        state.writes.push({ url, payload });
        return { data: { scholarship: { ...program, status: payload.status }, message: 'Decision recorded.' } };
    },
};
installModalScrollEnvironment();
const confirmationApp = createApp(OutcomeConfirmationHost);
confirmationApp.config.idPrefix = 'outcome-';
confirmationApp.mount('#fixture-confirmation');
createApp({
    render: () => h('div', [
        h(ProgramReviewRecordPage),
        h(RecipientAgreementDialog, { open: state.agreementOpen,
            agreement: { can_respond: true, status: 'pending', responsibilities: 'Remain enrolled and submit current grades.' },
            termsAccepted: state.termsAccepted, note: state.note,
            'onUpdate:termsAccepted': (value) => { state.termsAccepted = value; },
            'onUpdate:note': (value) => { state.note = value; },
            onClose: () => { state.agreementOpen = false; },
            onRespond: (outcome) => { state.writes.push({ agreement: outcome }); state.agreementOpen = false; },
        }),
        h(DecisionReopenDialog, { open: state.reopenOpen, decision: { stage_label: 'Exam', result_label: 'Passed' },
            onCancel: () => { state.reopenOpen = false; },
            onConfirm: (reason) => { state.writes.push({ reopened: reason }); state.reopenOpen = false; },
        }),
    ]),
}).mount('#fixture-page');
