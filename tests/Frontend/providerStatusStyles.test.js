import assert from 'node:assert/strict';
import test from 'node:test';

import {
    applicationStatusClass,
    benefitReleaseStatusClass,
    documentStatusClass,
    evidenceStatusClass,
    recipientRecordStatusClass,
} from '../../resources/js/support/providerStatusStyles.js';

test('successful provider workflow states use the positive treatment', () => {
    assert.match(applicationStatusClass('awarded'), /emerald/);
    assert.match(benefitReleaseStatusClass('released'), /emerald/);
    assert.match(documentStatusClass('accepted'), /emerald/);
    assert.match(recipientRecordStatusClass('completed'), /emerald/);
});

test('blocked and failed workflow states use the negative treatment', () => {
    assert.match(applicationStatusClass('rejected'), /rose/);
    assert.match(benefitReleaseStatusClass('withheld'), /rose/);
    assert.match(documentStatusClass('rejected'), /rose/);
    assert.match(evidenceStatusClass('missing'), /rose/);
});

test('states requiring provider attention remain visibly distinct', () => {
    assert.match(applicationStatusClass('submitted'), /amber/);
    assert.match(documentStatusClass('needs_replacement'), /amber/);
    assert.match(benefitReleaseStatusClass('prepared'), /sky/);
});
