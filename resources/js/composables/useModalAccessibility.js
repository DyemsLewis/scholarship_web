import { nextTick, onBeforeUnmount, watch } from 'vue';
import { lockPageScroll } from '../support/pageScrollLock';

export function useModalAccessibility(isOpen, dialog, close) {
    let previousFocus = null;
    let releaseScrollLock = null;

    function release() {
        if (!releaseScrollLock) return;
        releaseScrollLock();
        releaseScrollLock = null;
        previousFocus?.focus?.({ preventScroll: true });
        previousFocus = null;
    }

    watch(isOpen, async (open) => {
        if (!open) {
            release();
            return;
        }
        previousFocus = document.activeElement;
        releaseScrollLock ??= lockPageScroll();
        await nextTick();
        if (isOpen()) dialog.value?.focus({ preventScroll: true });
    }, { immediate: true });

    function handleKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
        } else if (event.key === 'Tab') {
            const elements = [...dialog.value.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex="0"]')]
                .filter((element) => element.offsetParent !== null && !element.closest('[inert]'));
            const first = elements[0];
            const last = elements.at(-1);
            if (!first) {
                event.preventDefault();
                dialog.value.focus();
            } else if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog.value)) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    onBeforeUnmount(release);
    return { handleKeydown };
}
