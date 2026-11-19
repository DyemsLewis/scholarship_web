import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { ref } from 'vue';

const files = ['components/FeedbackAlertModal.vue', 'components/NotificationBell.vue', 'components/ProviderVenueAddress.vue', 'components/case-reviews/RecipientCaseReviewLauncher.vue', 'components/case-reviews/RecipientCasePrivacySection.vue', 'pages/provider/programs/components/EligibilityOptionPicker.vue', 'pages/provider/programs/components/EligibilityLocationPicker.vue'];
const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
for (const file of files) test(file + ' compiles', () => {
    const { descriptor, errors } = parse(read(file));
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, id: file, filename: file, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
});

function feedback(retryLabel) {
    const script = parse(read(files[0])).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const message = ref('Existing message'), events = [];
    const state = new Function('defineModel', 'defineProps', 'defineEmits', script + '\nreturn { close, confirm };')(() => message, () => ({ retryLabel }), () => event => events.push(event));
    return { ...state, message, events };
}
test('dismissal clears only alert state, while retry clears it before re-running the original action', () => {
    const closed = feedback('Retry'); closed.close();
    assert.equal(closed.message.value, ''); assert.deepEqual(closed.events, []);
    const retried = feedback('Retry'); retried.confirm();
    assert.equal(retried.message.value, ''); assert.deepEqual(retried.events, ['retry']);
    const acknowledged = feedback(''); acknowledged.confirm(); assert.deepEqual(acknowledged.events, []);
});
test('all eight states use the existing shared dialog, including both privacy failures and the explicit-choice warning', () => {
    const expected = [[files[1], 1], [files[2], 1], [files[3], 1], [files[4], 2], [files[5], 2], [files[6], 1]];
    for (const [file, count] of expected) assert.equal((read(file).match(/<FeedbackAlertModal\b/g) || []).length, count, file);
    assert.match(read(files[0]), /<ConfirmationDialog/);
    assert.match(read(files[0]), /@click.stop/);
    for (const file of [files[2], files[3], files[4], files[5], files[6]]) assert.doesNotMatch(read(file), /<p v-if="(?:error|loadError)"/);
});
test('notifications prompt only for explicit provider interactions, preserving the existing behavior for other roles', () => {
    const source = read(files[1]);
    assert.match(source, /if \(!providerAlerts \|\| interactive\) errorMessage.value/);
    assert.match(source, /silent: notifications.value.length > 0, interactive: true/);
    assert.match(source, /<FeedbackToast v-else-if="errorMessage &amp;&amp; !providerAlerts"/);
});
test('dismissing venue errors retains manual fallback fields and typing does not re-open the alert', () => {
    const source = read(files[2]);
    assert.equal((source.match(/v-if="useManualLocation"/g) || []).length, 2);
    assert.match(source, /useManualLocation.value && previous && current\[0\] === previous\[0\]/);
    assert.match(source, /version\+\+/);
    assert.doesNotMatch(source, /v-if="error"/);
});
test('privacy error dialogs do not steal or discard form data, and stale saves reload rather than resubmit', () => {
    const source = read(files[4]);
    assert.match(source, /if \(!loadError.value\)/);
    assert.match(source, /stale \? 'Reload privacy status' : ''/);
    assert.match(source, /@retry="load\(\)"/);
    assert.match(source, /if \(!data\) return/);
});
