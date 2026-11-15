import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { computed, reactive, ref } from 'vue';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const read = name => readFileSync(new URL(`../../resources/js/pages/admin/reviews/${name}.vue`, import.meta.url), 'utf8');
for (const name of ['ApplicantReviewRecordPage', 'ApplicantVerificationProfileModal']) {
    test(`${name} compiles with the admin workspace`, () => {
        const { descriptor, errors } = parse(read(name)); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, filename: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('the main page keeps evidence and final decisions visible while profile and photo details are modal', () => {
    const page = read('ApplicantReviewRecordPage');
    assert.match(page, /<div class="mt-4 space-y-4" data-applicant-verification-layout>/);
    assert.ok(page.indexOf('id="supporting-evidence"') < page.indexOf('id="final-profile-verification"'));
    assert.match(page, /<ApplicantVerificationProfileModal :open="showProfile"/);
    assert.match(page, /aria-label="View complete applicant profile"/);
    assert.match(page, /@click="openProfile\('photo'\)"/);
    assert.match(page, /<template #default="\{ section \}"><ApplicantProfileDetails :sections="\[section\]"/);
    assert.match(page, /v-if="canReviewEvidence" id="final-profile-verification"/);
    assert.match(page, /show-data-source-notice/);
    assert.doesNotMatch(page, /<summary[^>]*>Applicant photo review|View complete applicant profile<\/summary>/);
    const form = page.slice(page.indexOf('<form v-if="canReviewEvidence"'), page.indexOf('</form>'));
    assert.doesNotMatch(form, /academic_result|grading_scale|type="number"/);
    assert.match(page, /expected_review_token: applicant.value.review_workflow.token/);
    assert.match(page, /evidenceReady.value && !missingEvidence.value.length/);
});

test('evidence rows have one labelled action and fit the review panel without a forced wide table', () => {
    const page = read('ApplicantReviewRecordPage');
    const table = page.slice(page.indexOf('<table'), page.indexOf('</table>'));
    assert.match(table, /table-fixed/); assert.match(table, /<colgroup>/);
    assert.doesNotMatch(table, /min-w-|truncate|<a /);
    assert.match(table, /:title="row.document\?\.original_name"/);
    assert.match(table, /:aria-label=/);
    assert.match(table, /row.state.class/);
    assert.match(table, /v-else class="text-xs text-slate-400">No file/);
});

test('profile modal uses shared focus/scroll locking and does not send review requests', () => {
    const modal = read('ApplicantVerificationProfileModal');
    assert.match(modal, /useModalAccessibility/); assert.match(modal, /Teleport to="body"/);
    assert.match(modal, /role="dialog" aria-modal="true"/);
    assert.match(modal, /role="tablist"/); assert.match(modal, /role="tabpanel"/);
    assert.match(modal, /overflow-y-auto/); assert.match(modal, /100dvh/);
    assert.doesNotMatch(modal, /axios|<form|v-html|window\.confirm/);
    for (const button of modal.match(/<button\b[\s\S]*?<\/button>/g)) assert.match(button, /type="button"/);
});

test('profile tabs are permission-safe, keyboard accessible, and cannot close a photo save in flight', () => {
    const script = parse(read('ApplicantVerificationProfileModal')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = reactive({ open: false, sections: [{ id: 'academic', title: 'Learning record' }], initialSection: 'academic', busy: false });
    const watches = [], events = [], focused = [];
    const state = new Function('defineProps', 'defineEmits', 'useId', 'computed', 'ref', 'watch', 'useModalAccessibility', script + '\nreturn { active, tabs, navigation, current, close, changeTab };')(
        () => props, () => event => events.push(event), () => 'profile-modal', computed, ref, (get, callback) => watches.push(callback), () => ({ handleKeydown() {} }),
    );
    assert.deepEqual(state.tabs.value.map(tab => tab.key), ['academic', 'photo']);
    watches[0](true); assert.equal(state.active.value, 'academic');
    state.navigation.value = { querySelectorAll: () => state.tabs.value.map((_, index) => ({ focus: () => focused.push(index) })) };
    const key = value => ({ key: value, preventDefault() {} });
    state.changeTab(key('ArrowLeft'), 0); assert.equal(state.active.value, 'photo'); assert.equal(focused.at(-1), 1);
    state.changeTab(key('ArrowRight'), 1); assert.equal(state.active.value, 'academic');
    props.busy = true; state.changeTab(key('End'), 0); assert.equal(state.active.value, 'academic'); state.close(); assert.deepEqual(events, []);
    props.busy = false; state.close(); assert.deepEqual(events, ['close']);
    props.initialSection = 'personal'; watches[0](true); assert.equal(state.active.value, 'academic');
    props.sections = []; watches[0](true); assert.equal(state.active.value, 'photo');
});
