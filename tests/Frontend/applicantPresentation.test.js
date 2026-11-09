import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const read = (path) => readFileSync(new URL(`../../resources/${path}`, import.meta.url), 'utf8');
const css = read('css/app.css');
const rule = (selector) => {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const match = css.match(new RegExp(`${escaped}\\s*\\{([^}]+)\\}`));
    assert.ok(match, `Missing shared style: ${selector}`);
    return match[1];
};

test('provider and scholarship detail labels use the same readable text style', () => {
    assert.match(rule('.student-detail-label'), /text-xs font-semibold leading-5 text-slate-600/);
    assert.doesNotMatch(rule('.student-detail-label'), /text-\[(9|10)px\]|text-slate-400|uppercase/);
    const provider = read('js/pages/UserProvidersPage.vue');
    const scholarship = read('js/pages/UserScholarshipDetailPage.vue');
    assert.match(provider, /class="student-detail-label">Current programs/);
    assert.match(scholarship, /class="student-detail-label">\{\{ fact.label \}\}/);
    assert.doesNotMatch(provider + scholarship, /<dt class="[^"]*text-slate-400/);
    assert.match(provider, /lg:min-h-\[390px\]/);
});

test('status badges share readable type, borders, corners and existing semantic colors', () => {
    assert.match(rule('.student-badge'), /min-h-7 max-w-full/);
    assert.match(rule('.student-badge'), /rounded-sm border/);
    assert.match(rule('.student-badge'), /text-xs font-semibold leading-4/);
    for (const [tone, palette] of [['positive', 'emerald'], ['attention', 'amber'], ['negative', 'rose'], ['info', 'sky']]) {
        assert.match(rule(`.student-badge-${tone}`), new RegExp(`border-${palette}-200 bg-${palette}-50 text-${palette}-[89]00`));
    }
    assert.doesNotMatch(rule('.student-badge'), /uppercase|tracking-/);
});

test('buttons distinguish normal and compact sizes while retaining keyboard focus and disabled feedback', () => {
    assert.match(rule('.student-button'), /min-h-11/);
    assert.match(rule('.student-button'), /rounded-sm border/);
    assert.match(rule('.student-button-compact'), /min-h-10 px-3 text-xs/);
    assert.match(rule('.student-button-icon'), /w-10 px-0/);
    assert.match(rule('.student-button:focus-visible'), /outline-2 outline-offset-2 outline-amber-600/);
    assert.match(rule(".student-button[aria-disabled='true']"), /cursor-not-allowed opacity-60/);
    assert.match(css, /\.student-button > i,[\s\S]*?\.student-badge > i\s*\{\s*@apply block w-4 shrink-0 text-center text-xs leading-none;/);
});

test('applicant cards share modest corners without blanket overrides of staff pages or controls', () => {
    for (const selector of ['.student-hero', '.student-card', '.student-soft-card', '.student-visual-card', '.student-empty-state']) assert.match(rule(selector), /rounded-md/);
    assert.doesNotMatch(css, /\.student-page\s+(?:button|a|span)\s*[{,]/);
    const header = read('js/components/TaskPageHeader.vue');
    assert.match(header, /isApplicant \? 'student-button student-button-on-dark' : 'rounded-md border border-slate-300/);
    assert.match(header, /isApplicant \? 'student-button student-button-accent' : 'rounded-md bg-slate-950/);
});

test('shared styles preserve document consent, reserved columns, file actions and review states', () => {
    const library = read('js/pages/UserDocumentsPage.vue');
    const documents = read('js/components/application-detail/ApplicationDocumentList.vue');
    assert.match(library, /lg:grid-cols-\[minmax\(0,1fr\)_9rem_14rem\]/);
    assert.match(documents, /md:grid-cols-\[minmax\(0,1fr\)_10rem_12rem\]/);
    assert.match(library, /:disabled="!preparedDocumentTermsAccepted/);
    assert.match(documents, /Boolean\(uploadingRequirement\) \|\| !uploadEnabled/);
    for (const [state, tone] of [['accepted', 'positive'], ['pending', 'info'], ['rejected', 'attention'], ['needs_replacement', 'attention']]) assert.ok(documents.includes(`${state}: 'student-badge-${tone}'`));
    assert.match(library, /@click="openDocumentPreview/);
    assert.match(documents, /emit\('preview', document\)/);
});

test('provider previews retain disabled contact actions, safe external links and existing images', () => {
    for (const page of ['UserProvidersPage', 'UserScholarshipDetailPage']) {
        const source = read(`js/pages/${page}.vue`);
        assert.match(source, /:aria-disabled="isPreview \|\| undefined" @click="blockPreviewAction"/);
        assert.match(source, /target="_blank" rel="noopener noreferrer"/);
        assert.match(source, /if \(isPreview\) event.preventDefault\(\)/);
        assert.match(source, /object-cover/);
    }
});

for (const path of ['pages/UserProvidersPage.vue', 'pages/UserScholarshipDetailPage.vue', 'components/TaskPageHeader.vue']) {
    test(`${path} compiles with the shared presentation styles`, () => {
        const source = read(`js/${path}`);
        const { descriptor, errors } = parse(source, { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}
