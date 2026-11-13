import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { programDraftSnapshot, programSaveLabel, shouldGuardProgramLink } from '../../resources/js/support/programSaveState.js';

test('program form connects leave protection and the keep-editing action', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /:cancel-label="confirmation.cancelLabel"/);
    assert.match(source, /addEventListener\('beforeunload', handleBeforeUnload\)/);
    assert.match(source, /removeEventListener\('beforeunload', handleBeforeUnload\)/);
    assert.match(source, /addEventListener\('click', handlePageLink, true\)/);
    assert.match(source, /savedSnapshot.value = draftSnapshot.value/);
});

test('snapshots track nested program data and image choices without incidental defaults or declaration state', () => {
    const form = { title: 'Grant', slotsAvailable: 20, benefits: [{ amount: 100 }], termsAccepted: false };
    const baseline = programDraftSnapshot(form);
    assert.equal(programDraftSnapshot({ ...form, slotsAvailable: '20', benefits: [{ amount: '100' }], termsAccepted: true }, 'default', 'recommended'), baseline);
    assert.notEqual(programDraftSnapshot({ ...form, title: 'New grant' }), baseline);
    assert.notEqual(programDraftSnapshot({ ...form, benefits: [{ amount: 200 }] }), baseline);
    assert.notEqual(programDraftSnapshot(form, 'system', 'campus'), programDraftSnapshot(form, 'system', 'students'));
    assert.notEqual(programDraftSnapshot(form, 'provider'), baseline);
});

test('save labels distinguish pending, failed, clean and saving states', () => {
    assert.equal(programSaveLabel({}), 'Not saved yet');
    assert.equal(programSaveLabel({ saved: true }), 'All changes saved');
    assert.equal(programSaveLabel({ dirty: true, saved: true }), 'Unsaved changes');
    assert.equal(programSaveLabel({ dirty: true, failed: true }), 'Changes not saved');
    assert.equal(programSaveLabel({ dirty: true, failed: true, saving: true }), 'Saving changes...');
});

test('only same-tab document navigation is guarded', () => {
    const current = 'https://portal.test/provider/programs/1/edit/basics';
    const event = (href, overrides = {}, linkOverrides = {}) => ({
        button: 0,
        target: { closest: () => ({ href, hasAttribute: () => false, target: '', ...linkOverrides }) },
        ...overrides,
    });
    assert.equal(shouldGuardProgramLink(event('/provider/programs'), current), true);
    assert.equal(shouldGuardProgramLink(event('https://outside.test'), current), true);
    assert.equal(shouldGuardProgramLink(event(current + '#section'), current), false);
    assert.equal(shouldGuardProgramLink(event('mailto:office@example.test'), current), false);
    for (const overrides of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }, { defaultPrevented: true }]) {
        assert.equal(shouldGuardProgramLink(event('/provider/programs', overrides), current), false);
    }
    assert.equal(shouldGuardProgramLink(event('/file', {}, { hasAttribute: () => true }), current), false);
    assert.equal(shouldGuardProgramLink(event('/provider/programs', {}, { target: '_blank' }), current), false);
});
