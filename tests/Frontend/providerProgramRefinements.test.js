import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { parse as parseTemplate } from '@vue/compiler-dom';

const read = (file) => readFileSync(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8');
const directory = read('pages/provider/programs/ProgramDirectoryPage.vue');
const record = read('pages/provider/programs/ProgramRecordPage.vue');
const form = read('pages/provider/programs/ProgramFormPage.vue');
const components = { ProgramDirectoryPage: directory, ProgramRecordPage: record, ProgramFormPage: form };
const ast = (source) => parseTemplate(parse(source).descriptor.template.content);
const find = (node, predicate) => predicate(node) ? node : (node.children ?? []).map((child) => find(child, predicate)).find(Boolean);
const attr = (node, name) => node.props?.find((prop) => prop.name === name)?.value?.content;

for (const [name, source] of Object.entries(components)) {
    test(`${name} compiles with locally scoped program refinements`, () => {
        const { descriptor, errors } = parse(source, { filename: `${name}.vue` });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        const result = compileTemplate({ id: name, filename: `${name}.vue`, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(result.errors, []);
        assert.match(source, /provider-program-workspace/);
        assert.match(source, /program-page-title/);
        assert.doesNotMatch(source, /truncate|line-clamp/);
    });
}

test('directories retain scoped search, filters, sorting, pagination, logos and monitoring destinations', () => {
    for (const value of ["'/provider/programs/data'", 'filter: activeFilter', "workspace: isMonitoringDirectory ? 'monitoring' : 'programs'", 'search: search.value.trim()', 'sort: sort.value', 'if (version !== requestVersion) return', 'staff-table-viewport', 'program.logo_url', 'program.monitoring.plan_url', 'program.record_url', 'pagination.current_page - 1', 'pagination.current_page + 1']) assert.ok(directory.includes(value), value);
    assert.match(directory, /<colgroup>[\s\S]*<col class="w-36"><\/colgroup>/);
    assert.match(directory, /min-w-\[56rem\]/);
    assert.doesNotMatch(directory, /min-w-\[68rem\]|w-\[10%\]/);
    assert.match(directory, /!programs.length && !errorMessage/);
    assert.match(directory, /:aria-busy="isLoading"/);
    assert.match(directory, /isLoading \? '\.\.\.' : summary.total/);
});

test('program records retain capacity, dates, complete summaries, stages, benefits and protected previews', () => {
    for (const value of ['program.capacity.applications', 'program.capacity.application_limit', 'program.capacity.recipient_slots', 'timelineRows', 'program.selection_stages', 'program.support.summary', 'program.support.benefit_count', 'program.actions.edit_url', 'ApplicantPagePreviewModal', 'program?.actions?.preview_data_url', 'program.workspace_links']) assert.ok(record.includes(value), value);
    assert.match(record, /:aria-expanded="showFullDescription"/);
    assert.match(record, /aria-controls="program-summary-description"/);
    assert.match(record, /id="program-summary-description"/);
    assert.match(record, /description.slice\(0, 360\)/);
});

test('all seven builder sections retain existing gates, bounds and visible current-step semantics', () => {
    assert.equal((form.match(/id: '(basics|support|dates-location|eligibility|application|selection|review)'/g) ?? []).length, 7);
    for (const value of [":aria-current=\"activeStep === step.id ? 'step' : undefined\"", '{{ step.hint }}', 'programConfigurationErrors', ':min="Number(form.slotsAvailable) || 1"', ':min="form.applicationOpensAt || undefined"', ':min="form.deadline || undefined"', ':disabled="isReadOnly || isSaving"', 'hasSavedProgram.value', 'applicationsCount.value === 0', 'configuredEligibilityRules', 'form.termsAccepted', 'submitProgram', 'saveDraft']) assert.ok(form.includes(value), value);
    assert.match(form, /:status="programStatusLabel"/);
    assert.match(form, /:preview-status="programStatusLabel"/);
    assert.match(form, /pending_review: 'Under review'/);
});

test('benefit modal scrolls fields independently from its persistent header and form footer', () => {
    const dialog = find(ast(form), (node) => attr(node, 'role') === 'dialog');
    assert.ok(dialog);
    assert.match(attr(dialog, 'class'), /max-h-\[calc\(100dvh-3rem\)\]/);
    assert.match(attr(dialog, 'class'), /flex-col overflow-hidden/);
    const modalForm = dialog.children.find((node) => node.tag === 'form');
    const scroll = modalForm.children.find((node) => attr(node, 'class')?.includes('program-benefit-scroll'));
    const footer = modalForm.children.find((node) => node.tag === 'footer');
    assert.ok(scroll && footer);
    assert.match(attr(scroll, 'class'), /min-h-0 flex-1 overflow-y-auto overscroll-contain/);
    assert.match(attr(footer, 'class'), /shrink-0/);
    assert.equal(find(scroll, (node) => node.tag === 'footer'), undefined);
    assert.ok(find(scroll, (node) => node.tag === 'fieldset'));
    assert.match(form, /useModalAccessibility\(\(\) => benefitDialogOpen.value, benefitDialog, closeBenefitDialog\)/);
    assert.match(form, /@keydown="handleBenefitKeydown"/);
    assert.equal(attr(dialog, 'tabindex'), '-1');
});

test('benefit Cancel discards the draft, saving stays local and read-only/busy states cannot change it', () => {
    const body = form.match(/function saveBenefit\(\) \{([\s\S]*?)\n\}/)[1];
    const run = (overrides = {}) => {
        const state = { benefitDraft: { value: { title: '  Learning allowance  ', type: 'allowance' } }, isReadOnly: { value: false }, isSaving: { value: false }, editingBenefitIndex: { value: null }, form: { value: { benefits: [] } }, closes: 0, ...overrides };
        new Function('benefitDraft', 'isReadOnly', 'isSaving', 'normalizedBenefit', 'benefitLabel', 'editingBenefitIndex', 'form', 'closeBenefitDialog', body)(state.benefitDraft, state.isReadOnly, state.isSaving, (value) => ({ ...value }), () => 'Allowance', state.editingBenefitIndex, state.form, () => state.closes++);
        return state;
    };
    const saved = run();
    assert.equal(saved.form.value.benefits[0].title, 'Learning allowance');
    assert.equal(saved.closes, 1);
    for (const override of [{ isReadOnly: { value: true } }, { isSaving: { value: true } }, { benefitDraft: { value: null } }]) {
        const state = run(override);
        assert.equal(state.form.value.benefits.length, 0);
        assert.equal(state.closes, 0);
    }
    assert.match(form, /benefitDraft.value = normalizedBenefit\(form.value.benefits\[index\]\)/);
    const close = form.match(/function closeBenefitDialog\(\) \{([\s\S]*?)\n\}/)[1];
    for (const value of ['benefitDialogOpen.value = false', 'benefitDraft.value = null', 'editingBenefitIndex.value = null']) assert.ok(close.includes(value));
    assert.doesNotMatch(body + close, /axios|fetch\(/);
});

test('program-only styles preserve other workspaces and the embedded applicant preview', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const part = css.split('/* Provider program refinements')[1].split('/* Shared dashboard')[0].replace(/^[\s\S]*?\*\//, '');
    for (const match of part.matchAll(/([^{}]+)\{/g)) {
        for (const selector of match[1].trim().split(/,\s*\n/)) assert.match(selector.trim(), /^\.provider-program-workspace/);
    }
    assert.match(part, /\.program-label\s*\{[^}]*font-size: 0.75rem/);
    assert.match(part, /\.program-text\s*\{[^}]*overflow-wrap: anywhere/);
    assert.match(part, /min-height: 2.5rem/);
    assert.match(part, /:focus-visible/);
    for (const value of [form, record]) assert.ok(value.indexOf('</section>\n\n        <') < value.indexOf('<ApplicantPagePreviewModal'));
});
