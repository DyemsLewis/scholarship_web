import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { capitalizeName } from '../../resources/js/support/capitalizeName.js';

function nameInput(value, start = value.length, end = start, direction = 'none') {
    let listener;
    const input = {
        value,
        selectionStart: start,
        selectionEnd: end,
        selectionDirection: direction,
        setSelectionRange(start, end, direction) {
            this.selectionStart = start;
            this.selectionEnd = end;
            this.selectionDirection = direction;
        },
        addEventListener(type, callback, capture) {
            assert.equal(type, 'input');
            assert.equal(capture, true);
            listener = callback;
        },
        removeEventListener(type, callback, capture) {
            assert.equal(type, 'input');
            assert.equal(capture, true);
            assert.equal(callback, listener);
            listener = null;
        },
        type(event = {}) {
            listener?.({ currentTarget: input, ...event });
        },
    };
    capitalizeName.beforeMount(input);
    return input;
}

test('only the first letter changes and paste preserves spacing, accents, and punctuation', () => {
    for (const [original, expected] of [
        ['maria clara', 'Maria clara'],
        ["dela Cruz-O'Neil", "Dela Cruz-O'Neil"],
        ['  \u00f1olasco', '  \u00d1olasco'],
        ['McDonald', 'McDonald'],
        ['', ''],
    ]) {
        const input = nameInput(original);
        input.type();
        assert.equal(input.value, expected);
        assert.equal(input.selectionStart, expected.length);
    }
});

test('the caret or selection is preserved even when uppercasing expands a letter', () => {
    const input = nameInput('\u00dfomer', 1, 4, 'backward');
    input.type();
    assert.equal(input.value, 'SSomer');
    assert.deepEqual([input.selectionStart, input.selectionEnd, input.selectionDirection], [2, 5, 'backward']);
});

test('typing with an input method is unchanged until composition ends; unmount removes the handler', () => {
    const input = nameInput('maria');
    input.type({ isComposing: true });
    assert.equal(input.value, 'maria');
    input.composing = true;
    input.type();
    assert.equal(input.value, 'maria');
    input.composing = false;
    input.type();
    assert.equal(input.value, 'Maria');
    capitalizeName.beforeUnmount(input);
    input.value = 'maria';
    input.type();
    assert.equal(input.value, 'maria');
});

test('registration and profile fields opt in without changing usernames or email addresses', () => {
    const read = (path) => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
    assert.match(read('app.js'), /\.directive\('capitalize-name', capitalizeName\)/);
    for (const [file, models] of [
        ['pages/RegisterPage.vue', ['firstName', 'lastName']],
        ['components/profile/ApplicantPersonalEditor.vue', ['first_name', 'last_name']],
        ['components/profile/ApplicantHouseholdEditor.vue', ['guardian_name']],
        ['pages/admin/accounts/AccountCreatePage.vue', ['first_name', 'last_name']],
        ['pages/provider/team/TeamAccountFormPage.vue', ['first_name', 'last_name']],
    ]) {
        const source = read(file);
        for (const model of models) assert.ok(source.includes('v-model="form.' + model + '" v-capitalize-name'), file + ': ' + model);
        assert.doesNotMatch(source, /v-model="form\.(?:username|email)" v-capitalize-name/);
    }
});
