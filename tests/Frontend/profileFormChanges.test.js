import assert from 'node:assert/strict';
import test from 'node:test';
import { profileFormHasChanges } from '../../resources/js/support/profileFormChanges.js';

test('restoring a numeric profile value clears the dirty state despite API formatting differences', () => {
    assert.equal(profileFormHasChanges({ gwa: 93 }, { gwa: '93.00' }), false);
    assert.equal(profileFormHasChanges({ gwa: 1.75, household_size: 5 }, { gwa: '1.750', household_size: '5' }), false);
    assert.equal(profileFormHasChanges({ latitude: 14.5, longitude: 121 }, { latitude: '14.5000000', longitude: '121.0000000' }), false);
});

test('actual changes, missing grades and invalid numeric values remain unsaved', () => {
    for (const pair of [[{ gwa: 92 }, { gwa: '93.00' }], [{ gwa: '' }, { gwa: 0 }], [{ gwa: 'not a grade' }, { gwa: 0 }], [{ household_size: 4 }, { household_size: 5 }]]) {
        assert.equal(profileFormHasChanges(...pair), true);
    }
});

test('text identifiers, phone leading zeros and consent booleans are never normalized as numbers', () => {
    assert.equal(profileFormHasChanges({ contact_number: '09175550001' }, { contact_number: '9175550001' }), true);
    assert.equal(profileFormHasChanges({ learner_reference_number: '00123' }, { learner_reference_number: '123' }), true);
    assert.equal(profileFormHasChanges({ guardian_is_account_owner: false }, { guardian_is_account_owner: true }), true);
    assert.equal(profileFormHasChanges({ first_name: 'Demo', guardian_is_account_owner: false }, { first_name: 'Demo', guardian_is_account_owner: false }), false);
});

test('comparison detects removed or added fields without mutating the original snapshot', () => {
    const saved = { gwa: '93.00', first_name: 'Demo' };
    const current = { gwa: 93, first_name: 'Demo' };
    assert.equal(profileFormHasChanges(current, saved), false);
    assert.deepEqual(saved, { gwa: '93.00', first_name: 'Demo' });
    assert.deepEqual(current, { gwa: 93, first_name: 'Demo' });
    assert.equal(profileFormHasChanges({ first_name: 'Demo' }, saved), true);
    assert.equal(profileFormHasChanges({ preferred_categories: ['STEM'] }, { preferred_categories: ['STEM'] }), false);
    assert.equal(profileFormHasChanges({ preferred_categories: ['STEM'] }, { preferred_categories: ['Arts'] }), true);
});
