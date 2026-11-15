import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { normalizeProgramRecipientAgreement } from '../../resources/js/support/programRecipientAgreement.js';

const fields = ['responsibilities', 'required_evidence', 'release_conditions', 'duration', 'noncompliance_consequence', 'exit_or_exception_process'];
const types = ['none', 'renewal', 'service', 'activities', 'reporting', 'custom'];

test('unanswered saved draft fields become empty strings for every recipient commitment option', () => {
    for (const commitment_type of types) {
        const saved = { commitment_type, ...Object.fromEntries(fields.map(field => [field, null])) };
        const before = JSON.stringify(saved);
        const form = normalizeProgramRecipientAgreement(saved);
        assert.equal(form.commitment_type, commitment_type);
        for (const field of fields) assert.equal(form[field].trim(), '');
        assert.equal(Boolean(form.release_conditions.trim()), false, 'Selecting a type must not bypass required conditions');
        assert.equal(JSON.stringify(saved), before);
    }
});

test('new, missing and partial agreements use the same complete editor defaults', () => {
    for (const saved of [undefined, null, {}, [], 'Old agreement text']) {
        const form = normalizeProgramRecipientAgreement(saved);
        assert.equal(form.commitment_type, 'provider_briefing');
        for (const field of fields) assert.equal(form[field], '');
    }
    const partial = normalizeProgramRecipientAgreement({ commitment_type: 'service', responsibilities: 'Attend the service activity.' });
    assert.equal(partial.responsibilities, 'Attend the service activity.');
    assert.equal(partial.duration, '');
});

test('saved terms and line breaks are preserved, while invalid field types cannot crash text validation', () => {
    const saved = { commitment_type: 'reporting', ...Object.fromEntries(fields.map(field => [field, `First line for ${field}\nSecond line`])) };
    assert.deepEqual(normalizeProgramRecipientAgreement(saved), saved);
    const legacy = normalizeProgramRecipientAgreement({ duration: 2, responsibilities: {}, required_evidence: [], release_conditions: false, noncompliance_consequence: NaN });
    assert.equal(legacy.duration, '2');
    for (const field of fields) assert.equal(typeof legacy[field].trim(), 'string');
});

test('the program builder normalizes both new forms and loaded programs without weakening agreement validation', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /recipientAgreement: normalizeProgramRecipientAgreement\(\)/);
    assert.match(source, /recipientAgreement: normalizeProgramRecipientAgreement\(program.recipient_agreement\)/);
    assert.match(source, /form\.value\.recipientAgreement\.release_conditions\.trim\(\)/);
    assert.match(source, /form\.value\.recipientAgreement\[field\]\.trim\(\)/);
});
