import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/components/ApplicantDashboardOverview.vue',
    '../../resources/js/pages/UserDashboardPage.vue',
];

for (const relativePath of components) {
    const component = relativePath.split('/').at(-1);

    test(`${component} compiles`, () => {
        const filename = new URL(relativePath, import.meta.url);
        const source = readFileSync(filename, 'utf8');
        const { descriptor, errors } = parse(source, { filename: component });

        assert.deepEqual(errors, []);

        const script = descriptor.scriptSetup
            ? compileScript(descriptor, { id: `${component}-${relativePath}` })
            : null;
        const template = compileTemplate({
            id: `${component}-${relativePath}`,
            filename: component,
            source: descriptor.template?.content ?? '',
            compilerOptions: { bindingMetadata: script?.bindings ?? {} },
        });

        assert.deepEqual(template.errors, []);
    });
}

test('desktop dashboard uses authentic Philippine classroom imagery and workflow progress', () => {
    const overview = readFileSync(new URL('../../resources/js/components/ApplicantDashboardOverview.vue', import.meta.url), 'utf8');
    const page = readFileSync(new URL('../../resources/js/pages/UserDashboardPage.vue', import.meta.url), 'utf8');

    assert.match(overview, /filipino-students-science-class\.jpg/);
    assert.match(overview, /filipino-students-group-study\.jpg/);
    assert.match(overview, /filipino-students-science-experiment\.jpg/);
    assert.match(overview, /filipino-students-reading-program\.jpg/);
    assert.doesNotMatch(overview, /applicant-dashboard-study\.jpg/);
    assert.doesNotMatch(overview, /filipino-(senior-high|campus|graduates)\.jpg/);
    assert.match(overview, /application\.progress_percent/);
    assert.match(overview, /application\.progress_label/);
    assert.match(overview, /lg:grid-cols-\[minmax\(0,1\.35fr\)_minmax\(20rem,0\.65fr\)\]/);
    assert.match(overview, /data-dashboard-summary-value class="shrink-0 text-right/);
    assert.match(page, /<ApplicantPageHeader[\s\S]*?compact/);
});
