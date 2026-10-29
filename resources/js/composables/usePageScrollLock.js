import { onBeforeUnmount, watch } from 'vue';
import { lockPageScroll } from '../support/pageScrollLock';

export function usePageScrollLock(isOpen) {
    let release = null;
    watch(isOpen, (open) => {
        if (open) release ??= lockPageScroll();
        else {
            release?.();
            release = null;
        }
    }, { immediate: true });
    onBeforeUnmount(() => release?.());
}
