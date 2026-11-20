function capitalizeNameInput(event) {
    const input = event.currentTarget;
    if (event.isComposing || input.composing) return;

    const match = /\p{L}/u.exec(input.value);
    if (!match) return;
    const [letter] = match;
    const uppercase = letter.toUpperCase();
    if (letter === uppercase) return;

    const { selectionStart, selectionEnd, selectionDirection } = input;
    input.value = input.value.slice(0, match.index) + uppercase + input.value.slice(match.index + letter.length);
    if (selectionStart !== null && selectionEnd !== null) {
        const shift = (position) => position > match.index ? position + uppercase.length - letter.length : position;
        input.setSelectionRange(shift(selectionStart), shift(selectionEnd), selectionDirection ?? 'none');
    }
}

export const capitalizeName = {
    beforeMount(input) {
        // Normalize the native value before v-model reads the input event.
        input.addEventListener('input', capitalizeNameInput, true);
    },
    beforeUnmount(input) {
        input.removeEventListener('input', capitalizeNameInput, true);
    },
};
