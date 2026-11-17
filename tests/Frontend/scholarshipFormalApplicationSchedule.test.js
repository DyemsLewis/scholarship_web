import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../../resources/js/components/ScholarshipFormalApplicationSchedule.vue', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/js/pages/UserScholarshipDetailPage.vue', import.meta.url), 'utf8');

test('the published formal schedule compiles', () => {
    const { descriptor, errors } = parse(source, { filename: 'ScholarshipFormalApplicationSchedule.vue' });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'published-formal-schedule' });
    const template = compileTemplate({ id: 'published-formal-schedule', filename: 'ScholarshipFormalApplicationSchedule.vue', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
    assert.deepEqual(template.errors, []);
});

test('how to apply keeps one content column without the formal application schedule', () => {
    assert.match(page, /class="grid grid-cols-1 items-start gap-4" data-scholarship-how-to-apply/);
    assert.doesNotMatch(page, /ScholarshipFormalApplicationSchedule|process\.formal_application|data-scholarship-formal-schedule/);
    assert.match(page, /v-for="stage in process\.stages"/);
    assert.match(page, /v-if="stage\.key === 'formal_application'"[\s\S]*?After pre-screening[\s\S]*?See your application record for instructions/);
    assert.match(page, /<div v-else class="sm:text-right">[\s\S]*?stage\.timing\?\.label/);
});

test('the assigned formal application instructions remain in application details', () => {
    const application = readFileSync(new URL('../../resources/js/pages/UserApplicationActivitiesPage.vue', import.meta.url), 'utf8');
    const handoff = readFileSync(new URL('../../resources/js/components/application-detail/FormalApplicationHandoff.vue', import.meta.url), 'utf8');
    assert.match(application, /<FormalApplicationHandoff/);
    assert.match(application, /@map="openHandoffMap"/);
    assert.match(handoff, /data-application-formal-schedule/);
});

test('the schedule keeps its venue map preparation and honest deadline wording', () => {
    assert.match(source, /<LocationMapLink :address="mapAddress" :latitude="location.latitude" :longitude="location.longitude"/);
    assert.match(source, /hasLocationPin\(location.value.latitude, location.value.longitude\)/);
    assert.match(source, /:open="requirements.length <= 3"/);
    assert.match(source, /completion deadline, not an assigned appointment/);
    assert.match(source, /Provider will confirm/);
    assert.match(source, /Only continue after passing pre-screening/);
});

test('provider preview cannot follow the public external application link', () => {
    assert.match(source, /:href="preview \? undefined : schedule.url"/);
    assert.match(source, /:aria-disabled="preview \|\| undefined"/);
    assert.match(source, /@click="preview && \$event.preventDefault\(\)"/);
    assert.match(source, /rel="noopener noreferrer"/);
});
