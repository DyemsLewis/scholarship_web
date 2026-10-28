import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { applicantProfileOverview } from '../../resources/js/support/applicantProfileOverview.js';

const verified = () => ({
    readiness: { complete: true, verified: true, percent: 100, missing: [] },
    verificationStatus: 'approved',
    requirements: [{ value: 'academic_record', required: true }, { value: 'recent_school_id', required: true }, { value: 'extra_record', required: false }],
    documents: [{ document_type: 'academic_record', status: 'approved' }, { document_type: 'recent_school_id', status: 'approved' }],
});

test('only a complete, verified saved profile is presented as ready', () => {
    const state = applicantProfileOverview(verified());
    assert.equal(state.key, 'ready');
    assert.equal(state.label, 'Verified');
    assert.equal(state.canApply, true);
    assert.equal(state.action, null);
    assert.equal(state.required, 2);
    assert.equal(state.uploaded, 2);
    assert.match(state.description, /supporting records are approved/);
});

test('100 percent complete is not the same as verified', () => {
    const state = applicantProfileOverview({ ...verified(), readiness: { complete: true, verified: false, percent: 100 }, verificationStatus: 'pending' });
    assert.equal(state.key, 'under_review');
    assert.equal(state.canApply, false);
    assert.equal(state.action, null);
    assert.match(state.description, /verified before you can apply/);
    assert.doesNotMatch(state.title, /ready for applications/);
});

test('missing saved fields point to setup even when older evidence was approved', () => {
    const state = applicantProfileOverview({ ...verified(), readiness: { complete: false, verified: true, percent: 75, missing: [{ key: 'city', label: 'City' }] } });
    assert.equal(state.key, 'details_needed');
    assert.equal(state.percent, 75);
    assert.equal(state.action.field, 'city');
    assert.equal(state.canApply, false);
});

test('required evidence is counted separately from optional uploads and blocks premature readiness', () => {
    const state = applicantProfileOverview({ ...verified(), documents: [{ document_type: 'academic_record', status: 'approved' }, { document_type: 'extra_record', status: 'submitted' }] });
    assert.equal(state.key, 'files_needed');
    assert.equal(state.uploaded, 1);
    assert.equal(state.required, 2);
    assert.equal(state.canApply, false);
    assert.equal(state.action.section, 'verification');
});

test('returned records point to reviewer changes instead of a waiting or successful state', () => {
    for (const values of [
        { verificationStatus: 'rejected' },
        { documents: [{ document_type: 'academic_record', status: 'rejected' }, { document_type: 'recent_school_id', status: 'approved' }] },
    ]) {
        const state = applicantProfileOverview({ ...verified(), ...values });
        assert.equal(state.key, 'changes_requested');
        assert.equal(state.canApply, false);
        assert.equal(state.action.section, 'verification');
    }
});

test('academic scanning attention stays actionable but cannot undo a genuine manual approval', () => {
    const values = { ...verified(), scanNeedsAttention: true };
    assert.equal(applicantProfileOverview(values).key, 'ready');
    const pending = applicantProfileOverview({ ...values, readiness: { complete: true, verified: false }, verificationStatus: 'pending' });
    assert.equal(pending.key, 'academic_attention');
    assert.equal(pending.action.section, 'verification');
    assert.equal(pending.canApply, false);
});

test('a photo replacement request remains visible without changing the backend application gate', () => {
    const state = applicantProfileOverview({ ...verified(), photoReviewStatus: 'needs_replacement' });
    assert.equal(state.key, 'photo_requested');
    assert.equal(state.action.section, 'personal');
    assert.equal(state.canApply, true);
    assert.doesNotMatch(state.description, /cannot apply/);
});

test('unsubmitted, unknown, and inconsistent verification states never imply approval', () => {
    for (const verificationStatus of ['unsubmitted', 'unknown', '']) {
        const state = applicantProfileOverview({ ...verified(), verificationStatus });
        assert.equal(state.key, 'verification_required');
        assert.equal(state.canApply, false);
        assert.ok(state.action);
    }
    assert.equal(applicantProfileOverview().key, 'details_needed');
    assert.equal(applicantProfileOverview({ ...verified(), readiness: { complete: true, verified: false } }).canApply, false);
});

test('readiness numbers remain safe for absent or unexpected values', () => {
    for (const [value, expected] of [[-5, 0], [105, 100], ['invalid', 0], [null, 0]]) {
        assert.equal(applicantProfileOverview({ readiness: { percent: value } }).percent, expected);
    }
});

for (const component of ['ApplicantProfileIdentity', 'ApplicantProfileReadiness']) {
    test(`${component} compiles in the applicant design system`, () => {
        const source = readFileSync(new URL(`../../resources/js/components/profile/${component}.vue`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source, { filename: component });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: component });
        const result = compileTemplate({ id: component, filename: component, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(result.errors, []);
    });
}

test('the foundation keeps one readiness strip above the record fields without changing staff headers', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileRecord.vue', import.meta.url), 'utf8');
    const identity = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileIdentity.vue', import.meta.url), 'utf8');
    assert.equal((page.match(/<ApplicantProfileReadiness /g) ?? []).length, 1);
    assert.match(page, /<template #header>\s*<ApplicantProfileIdentity[^]*?<ApplicantProfileReadiness/);
    assert.match(record, /<slot name="header">[^]*?<header[^]*?<\/header>\s*<\/slot>/);
    assert.doesNotMatch(page, /overviewProfileAction|Records and readiness/);
    assert.match(page, /:show-trust-notice="false"/);
    assert.match(page, /readiness: savedProfileReadiness\.value/);
    assert.match(identity, /font-display/);
    assert.match(identity, /default-applicant-avatar\.jpg/);
    assert.doesNotMatch(identity, /record\.initials|<figure|location|email/);
    assert.match(page, /v-else-if="!user"/);
});
