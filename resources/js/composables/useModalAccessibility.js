import { nextTick, onBeforeUnmount, watch } from 'vue';

export function useModalAccessibility(isOpen, dialog, close) {
    let previousFocus = null;
    let previousOverflow = '';
    let locked = false;

    function release() {
        if (!locked) return;
        document.body.style.overflow = previousOverflow;
        locked = false;
        previousFocus?.focus?.();
        previousFocus = null;
    }

    watch(isOpen, async (open) => {
        if (!open) {
            release();
            return;
        }
        previousFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        locked = true;
        await nextTick();
        if (isOpen()) dialog.value?.focus();
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
