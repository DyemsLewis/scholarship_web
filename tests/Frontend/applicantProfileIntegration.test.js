import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { profileEducationSummary, profileValueLabel, profileVerificationLabel } from '../../resources/js/support/applicantProfileLabels.js';

test('profile labels describe declarations rather than exposing ambiguous machine keys', () => {
    assert.equal(profileValueLabel('currentScholarship', 'none'), 'No scholarship outside this portal');
    assert.equal(profileValueLabel('gradingScale', 'grade_point'), 'GWA grade point');
    assert.equal(profileValueLabel('educationLevel', 'tvet'), 'TVET / Vocational');
    assert.equal(profileValueLabel('citizenship', 'dual_filipino'), 'Dual citizen, including Filipino');
    assert.equal(profileValueLabel('unknown', 'unrecognized_key'), 'Unrecognized Key');
    assert.equal(profileValueLabel('gender', null), '');
    assert.equal(profileVerificationLabel('approved'), 'Evidence reviewed');
    assert.equal(profileVerificationLabel('pending'), 'Under review');
    assert.equal(profileVerificationLabel('rejected'), 'Needs replacement');
    assert.equal(profileVerificationLabel('unknown'), 'Not submitted');
    assert.equal(profileEducationSummary({ education_level: 'senior_high_school', course_or_strand: 'STEM', year_level: 'Grade 12' }), 'Senior High School - STEM - Grade 12');
    assert.equal(profileEducationSummary(null), '');
});

test('all staff profile records reuse the saved-record mapper and retain source notices', () => {
    for (const file of [
        'provider/reviews/ApplicationReviewRecordPage.vue', 'provider/decisions/FinalDecisionRecordPage.vue',
        'admin/reviews/ApplicantReviewRecordPage.vue', 'AdminApplicantReviewPage.vue',
    ]) {
        const source = readFileSync(new URL(`../../resources/js/pages/${file}`, import.meta.url), 'utf8');
        assert.match(source, /applicantProfileDetails\(applicant.value, \{ omitUnavailable: true \}\)/);
        assert.match(source, /educationSummary: profileEducationSummary/);
        assert.match(source, /show-data-source-notice/);
        assert.match(source, /:verification-label="profileVerificationLabel/);
        assert.doesNotMatch(source, /const applicantProfileSections = computed\(\(\) => \[/);
    }
    const record = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileRecord.vue', import.meta.url), 'utf8');
    assert.match(record, /<ApplicantProfileDetails[^]*?:editable="editable"/);
    assert.match(record, /default-applicant-avatar.jpg/);
    assert.match(record, /@error="photoUnavailable = true"/);
    assert.doesNotMatch(record, /record.initials|truncate/);
});

test('nested maps and file previews own focus and restore their previous scroll lock', () => {
    const helper = readFileSync(new URL('../../resources/js/composables/useModalAccessibility.js', import.meta.url), 'utf8');
    assert.match(helper, /previousOverflow = document.body.style.overflow/);
    assert.match(helper, /document.body.style.overflow = previousOverflow/);
    assert.match(helper, /previousFocus\?\.focus/);
    assert.match(helper, /event.stopPropagation\(\)/);
    assert.match(helper, /event.key === 'Tab'/);
    for (const file of ['LocationMapModal', 'FilePreviewModal']) {
        const source = readFileSync(new URL(`../../resources/js/components/${file}.vue`, import.meta.url), 'utf8');
        assert.match(source, /useModalAccessibility/);
        assert.match(source, /ref="dialog"/);
        assert.match(source, /@keydown="handleKeydown"/);
    }
});
