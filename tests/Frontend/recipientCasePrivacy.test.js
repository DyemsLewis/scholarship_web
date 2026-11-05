import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
const read = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');
const file = 'components/case-reviews/RecipientCasePrivacySection.vue';
test('privacy section compiles and loads only when expanded', () => {
    const { descriptor, errors } = parse(read(file), { filename: file });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: file, id: file, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.ok(read(file).includes('event.target.open && !privacy.value'));
    assert.ok(!read(file).includes('onMounted'));
});
test('privacy has no approve-disclosure control and requires reasons and acknowledgment', () => {
    const source = read(file);
    for (const token of ['value="blocked"', 'value="not_necessary"', 'value="policy_review_needed"', 'expected_revision:', 'request_key:', 'useConcernMutation', 'privacy.value?.can_record && confirmed.value', 'rationale.value.trim().length >= 30', 'audit_retention_hold:', 'recheck_on:', 'Reload privacy status']) assert.ok(source.includes(token), token);
    assert.ok(!source.includes('value="approved"'));
    assert.ok(!source.includes('sharing_enabled: true'));
});
test('privacy edits participate in parent close guards and cannot race review actions', () => {
    const child = read(file), parent = read('components/case-reviews/RecipientCaseReviewDialog.vue');
    for (const token of ["emit('dirty-change', value)", "emit('busy-change', value)", "version++; reset()", 'confirmed.value = false']) assert.ok(child.includes(token));
    for (const token of ['privacyDirty.value ||', 'privacyBusy.value', 'if (locked.value || privacyDirty.value) return', '@dirty-change="privacyDirty = $event"', '@recorded="privacyRecorded"', '!action && current.privacy_url']) assert.ok(parent.includes(token));
});
test('access history is paginated and distinguishes authorized access from document reading', () => {
    const source = read(file);
    for (const token of ['access?.last_page > 1', 'privacy.assessments.last_page > 1', 'not proof that a document was read', 'using team labels for staff', 'assessment_state_label', 'audit_retention_hold']) assert.ok(source.includes(token));
    assert.ok(!source.includes('v-html'));
    assert.ok(read('composables/useModalAccessibility.js').includes("!element.matches(':disabled')"));
    assert.ok(source.includes("?.focus({ preventScroll: true })"));
});
