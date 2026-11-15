import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const source = readFileSync(new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url), 'utf8');

test('application cards compile with a responsive action group', () => {
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'card-actions' });
    assert.deepEqual(compileTemplate({ id: 'card-actions', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /data-application-card-actions[^>]+grid w-full gap-2 xl:w-auto/);
    assert.match(source, /data-application-card-actions[^>]+sm:grid-cols-2/);
});

test('application and support links share height, width, borders, and icon alignment', () => {
    for (const name of ['record', 'support']) {
        const tag = source.match(new RegExp(`<a\\s[^>]*data-application-${name}-link[^>]*>`))?.[0];
        assert.ok(tag, name);
        assert.match(tag, /h-11 w-full/);
        assert.match(tag, /rounded-md border /);
        assert.match(tag, /leading-5/);
        assert.doesNotMatch(tag, /sm:w-auto/);
    }
    assert.equal((source.match(/block w-3\.5 text-center text-xs leading-none/g) || []).length, 2);
    assert.match(source, /:href="applicationActionUrl\(application\)"/);
    assert.match(source, /:href="`\/dashboard\/monitoring\/\$\{application.id\}`"/);
    assert.match(source, /v-if="application.recipient_agreement\?\.status === 'accepted' && application.recipient_monitoring\?\.eligible"/);
});
