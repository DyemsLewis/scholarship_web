import { confirmChange, confirmOutcome, registerOutcomeConfirmationPresenter } from '../../../resources/js/support/outcomeConfirmation.js';

// Existing mutation tests exercise the save after the user confirms.
registerOutcomeConfirmationPresenter(async () => true);
export { confirmChange, confirmOutcome };
export const afterConfirmation = () => new Promise((resolve) => setImmediate(resolve));
