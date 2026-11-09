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
    assert.match(source, /<ScholarshipApplicationGuide/);
    assert.match(source, /<ScholarshipEligibilityPanel/);
    assert.match(source, /<ScholarshipAwardDetails :content="content"/);
    assert.match(source, /<ScholarshipApplicationRequirements/);
    assert.match(source, /data-scholarship-application-process/);
    assert.match(source, /data-scholarship-provider/);
    assert.match(source, /content\.primary_benefit/);
    assert.match(source, /scholarshipVisual\.hero/);
    assert.match(source, /sectionVisual\.image/);
    assert.match(source, /See benefits and terms/);
    assert.doesNotMatch(source, /lg:min-h-\[390px\]/);
    assert.doesNotMatch(source, /factIcon/);
    assert.doesNotMatch(source, /decisionIcon/);
    assert.doesNotMatch(source, /\{\{ page\.title \}\}/);
    assert.doesNotMatch(source, /sm:grid-cols-\[14rem_minmax\(0,1fr\)\]/);
});

test('the header groups the cover and scholarship facts, with the changing action directly below', () => {
    const header = source.slice(source.indexOf('data-scholarship-detail-header'), source.indexOf('</header>', source.indexOf('data-scholarship-detail-header')));

    assert.doesNotMatch(header, /<ScholarshipDetailAction|decision\./);
    assert.match(header, /<figure v-if="isOverview"[^>]+hidden[^>]+lg:block[^>]+data-scholarship-cover/);
    assert.match(header, /:src="scholarshipImage" data-scholarship-logo/);
    assert.match(header, /:src="scholarshipVisual\.hero"/);
    assert.match(header, /<dl v-if="isOverview && snapshot\.length"[^>]+data-scholarship-key-facts/);
    assert.match(source, /<\/header>\s+<ScholarshipDetailAction v-if="!isApplicationView"[^>]*\/>\s+<nav id="scholarship-section"/);
});

test('scholarship sections remain direct links and land with the selected section announced', () => {
    const navigation = source.slice(source.indexOf('<nav id="scholarship-section"'), source.indexOf('</nav>'));

    assert.match(navigation, /grid-cols-6[\s\S]*md:grid-cols-5/);
    assert.doesNotMatch(navigation, /overflow-x-auto|min-w-max/);
    assert.match(navigation, /props\.embedded \? item\.href : `\$\{item\.href\}\$\{sectionAnchor\}`/);
    assert.match(navigation, /aria-current="item\.active \? 'page'/);
    assert.match(source, /navigationType === 'back_forward' \|\| navigationType === 'reload'/);
    assert.match(source, /sectionNavigation\.value\?\.scrollIntoView/);
    assert.match(source, /sectionContent\.value\?\.focus\(\{ preventScroll: true \}\)/);
    assert.match(source, /ref="sectionContent"[^>]+:aria-label="sectionLabel"[^>]+tabindex="-1"/);
});

test('eligibility verification guidance reuses the actual profile step rather than assuming evidence is pending', () => {
    assert.match(source, /profileStep\?\.description/);
    assert.doesNotMatch(source, /while an authorized reviewer checks your supporting evidence/);
    assert.match(source, /content.preview_scenario \|\| previewScenario/);
});

test('only a server-allowed start can get the nonblocking preference headline', () => {
    assert.match(source, /!isPreview && decision\.value\.can_apply === true && preferences/);
    assert.match(source, /You can apply\. One preference differs\./);
    assert.match(source, /v-if="fact\.note"/);
});
