import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/pages/UserScholarshipDetailPage.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('applicant scholarship details page compiles', () => {
    const { descriptor, errors } = parse(source, { filename: 'UserScholarshipDetailPage.vue' });

    assert.deepEqual(errors, []);

    const script = compileScript(descriptor, { id: 'applicant-scholarship-detail' });
    const template = compileTemplate({
        id: 'applicant-scholarship-detail',
        filename: 'UserScholarshipDetailPage.vue',
        source: descriptor.template?.content ?? '',
        compilerOptions: { bindingMetadata: script.bindings },
    });

    assert.deepEqual(template.errors, []);
});

test('scholarship details use a focused visual hierarchy', () => {
    assert.match(source, /data-scholarship-detail-header/);
    assert.match(source, /data-scholarship-detail-navigation/);
    assert.match(source, /data-scholarship-overview/);
    assert.match(source, /data-scholarship-readiness/);
    assert.match(source, /data-scholarship-eligibility/);
    assert.match(source, /data-scholarship-benefits/);
    assert.match(source, /data-scholarship-terms/);
    assert.match(source, /data-scholarship-application-files/);
    assert.match(source, /data-scholarship-application-process/);
    assert.match(source, /data-scholarship-provider/);
    assert.match(source, /content\.primary_benefit/);
    assert.match(source, /scholarshipVisual\.hero/);
    assert.match(source, /sectionVisual\.image/);
    assert.match(source, /See benefits and terms/);
    assert.match(source, /lg:min-h-\[390px\]/);
    assert.doesNotMatch(source, /factIcon/);
    assert.doesNotMatch(source, /decisionIcon/);
    assert.doesNotMatch(source, /\{\{ page\.title \}\}/);
    assert.doesNotMatch(source, /sm:grid-cols-\[14rem_minmax\(0,1fr\)\]/);
});
