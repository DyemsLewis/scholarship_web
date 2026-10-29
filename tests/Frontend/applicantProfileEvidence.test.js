import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { evidenceFilePolicy, evidenceProgress, evidenceStatus } from '../../resources/js/support/applicantProfileEvidence.js';

test('evidence statuses never confuse an uploaded file with accepted proof', () => {
    assert.equal(evidenceStatus(null).label, 'Not uploaded');
    assert.equal(evidenceStatus({ status: 'submitted' }).label, 'Awaiting review');
    assert.equal(evidenceStatus({ status: 'approved' }).label, 'Accepted');
    assert.equal(evidenceStatus({ status: 'rejected' }).label, 'Needs replacement');
    assert.equal(evidenceStatus({ status: 'unknown_value' }).label, 'Awaiting review');
});

test('required evidence progress excludes optional records and distinguishes returned files', () => {
    assert.deepEqual(evidenceProgress([
        { required: true, document: { status: 'approved' } },
        { required: true, document: { status: 'submitted' } },
        { required: true, document: { status: 'rejected' } },
        { required: true },
        { required: false, document: { status: 'approved' } },
    ]), { total: 4, saved: 3, accepted: 1, needsReplacement: 1 });
    assert.deepEqual(evidenceProgress(), { total: 0, saved: 0, accepted: 0, needsReplacement: 0 });
});

test('school IDs and scanned grades keep the server file restrictions', () => {
    const ocr = { configured: true, max_file_size_mb: 2 };
    assert.deepEqual(evidenceFilePolicy('academic_record', ocr), {
        accept: '.pdf,.jpg,.jpeg,.png', maxMb: 2, label: 'PDF, JPG or PNG; up to 2 MB',
    });
    assert.equal(evidenceFilePolicy('recent_school_id', ocr).maxMb, 5);
    assert.doesNotMatch(evidenceFilePolicy('recent_school_id').accept, /doc/);
    assert.match(evidenceFilePolicy('income_record', ocr).accept, /docx/);
    assert.equal(evidenceFilePolicy('academic_record', { configured: false }).maxMb, 5);
    assert.match(evidenceFilePolicy('academic_record', { configured: false }).accept, /docx/);
    assert.equal(evidenceFilePolicy('academic_record', { configured: true, max_file_size_mb: 'bad' }).maxMb, 1);
});

for (const file of ['profile/ApplicantEvidenceEditor.vue', 'profile/ApplicantProfileEvidenceList.vue', 'TermsAgreement.vue', 'TermsModal.vue']) {
    test(`${file} compiles with the modal workflow`, () => {
        const source = readFileSync(new URL(`../../resources/js/components/${file}`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source, { filename: file });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        const template = compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('evidence guidance is progressive while review notes and status remain visible', () => {
    const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEvidenceList.vue', import.meta.url), 'utf8');
    assert.match(source, /<details[^]*?What to upload[^]*?row\.description[^]*?row\.supports[^]*?row\.context/);
    assert.ok(source.indexOf('row.document?.review_notes') < source.indexOf('<details'));
    assert.match(source, /data-evidence-status/);
    assert.match(source, /md:grid-cols-\[minmax\(0,1fr\)_9rem_14rem\]/);
    assert.match(source, /:aria-label="`\$\{row.document \? 'Replace' : 'Upload'\}/);
    assert.doesNotMatch(source, /truncate|shadow|student-card/);
});

test('the editor has one scroll area and protects saves and nested dialogs', () => {
    const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileEditModal.vue', import.meta.url), 'utf8');
    assert.match(source, /data-profile-editor-body/);
    assert.match(source, /<fieldset :disabled="working"/);
    assert.match(source, /if \(!working.value\) emit\('close'\)/);
    assert.match(source, /if \(props.suspended\) return/);
    assert.match(source, /event.stopPropagation\(\)/);
    assert.match(source, /summary, button/);
    assert.match(source, /Uploads and removals are saved immediately/);
    assert.match(source, /Profile changes not saved/);
});

test('evidence uses saved profile context and keeps upload terms above the editor', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const options = source.slice(source.indexOf('const academicProofOption'), source.indexOf('const applicantAge'));
    assert.doesNotMatch(options, /form\.value/);
    assert.match(source, /title: 'Save before opening supporting evidence\?'/);
    assert.match(source, /Unable to upload this record/);
    const editor = readFileSync(new URL('../../resources/js/components/profile/ApplicantEvidenceEditor.vue', import.meta.url), 'utf8');
    assert.match(editor, /:modal-layer="2700"/);
    assert.match(editor, /fallback-academic-result/);
    assert.match(editor, /v-if="manualResultAllowed" #academic-result/);
    assert.doesNotMatch(source, /Save entered result/);
    const terms = readFileSync(new URL('../../resources/js/components/TermsModal.vue', import.meta.url), 'utf8');
    assert.match(terms, /:style="\{ zIndex: layer \}"/);
    assert.match(terms, /previouslyFocused\?\.focus/);
    assert.match(terms, /releaseScrollLock \?\?= lockPageScroll\(\)/);
    assert.doesNotMatch(terms, /body.classList/);
});
