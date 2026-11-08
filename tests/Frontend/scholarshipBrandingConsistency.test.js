import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('scholarship detail identity uses its own logo and a separate cover', () => {
    const source = readFileSync('resources/js/pages/UserScholarshipDetailPage.vue', 'utf8');
    assert.match(source, /const scholarshipImage = computed\(\(\) => identity\.value\?\.logo_url \|\| '\/uploads\/scholarship-default.jpg'\)/);
    assert.match(source, /const chosenCover = identity\.value\?\.cover_image_url/);
    assert.doesNotMatch(source, /const scholarshipImage = computed\(\(\) => identity\.value\?\.provider/);
});

test('provider program cards never stretch a logo into the background or reuse the organization mark', () => {
    const source = readFileSync('resources/js/pages/UserProvidersPage.vue', 'utf8');
    const cards = source.slice(source.indexOf('data-provider-programs'));
    assert.match(cards, /:src="program\.cover_image_url \|\| programVisual\(program\)\.image"/);
    assert.match(cards, /:src="program\.logo_url \|\| '\/uploads\/scholarship-default.jpg'"/);
    assert.doesNotMatch(cards, /:src="program\.image_url|:src="provider\.logo_url/);
    assert.match(cards, /data-program-cover/);
    assert.match(cards, /data-program-logo/);
});
