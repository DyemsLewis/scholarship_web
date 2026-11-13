import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('resources/js/pages/UserScholarshipsPage.vue', 'utf8');
const body = source.match(/function scholarshipLogo\(scholarship\) \{([\s\S]*?)\n\}/)[1];
const scholarshipLogo = new Function('scholarship', body);

test('catalog identity images choose the program logo rather than its background', () => {
    assert.equal(scholarshipLogo({ logo_url: '/program-logo.png', image_url: '/background.jpg', cover_image_url: '/cover.jpg' }), '/program-logo.png');
    for (const program of [null, {}, { logo_url: '', image_url: '/background.jpg' }]) {
        assert.equal(scholarshipLogo(program), '/uploads/scholarship-default.jpg');
    }
});

test('find, saved, quick preview and comparison images share the logo selector', () => {
    assert.equal((source.match(/:src="scholarshipLogo\(/g) || []).length, 4);
    assert.equal((source.match(/data-scholarship-logo/g) || []).length, 4);
    assert.doesNotMatch(source, /:src="scholarshipImage\(/);
});

test('catalog and preview provider names use a clear byline', () => {
    assert.match(source, /By \{\{ scholarship\.provider\.name \|\| 'Scholarship provider' \}\}/);
    assert.match(source, /By \{\{ previewScholarship\.provider\?\.name \|\| 'Scholarship provider' \}\}/);
    assert.equal((source.match(/By \{\{ scholarship\.provider\?\.name/g) || []).length, 2);
    assert.match(source, />By scholarship provider<\/p>/);
});
