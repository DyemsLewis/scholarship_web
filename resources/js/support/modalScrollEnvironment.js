import { lockPageScroll } from './pageScrollLock.js';

export function installModalScrollEnvironment(doc = document, view = window, Observer = MutationObserver) {
    let release = null;
    function sync() {
        const open = [...doc.querySelectorAll('[role="dialog"][aria-modal="true"], [role="alertdialog"][aria-modal="true"]')]
            .some((dialog) => !dialog.hidden && dialog.getClientRects().length > 0 && view.getComputedStyle(dialog).visibility !== 'hidden');
        if (open) release ??= lockPageScroll(doc, view);
        else {
            release?.();
            release = null;
        }
    }
    // Cover inline dialogs as well as reusable modal components across all roles.
    const observer = new Observer(sync);
    observer.observe(doc.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-modal', 'role', 'hidden', 'style', 'class'] });
    sync();
    return () => {
        observer.disconnect();
        release?.();
        release = null;
    };
}
