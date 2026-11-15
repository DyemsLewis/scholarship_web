import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileIdentity.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = compileScript(descriptor, { id: 'profile-color', inlineTemplate: true });
const code = script.content.replaceAll('from "vue"', `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replaceAll("from 'vue'", `from ${JSON.stringify(import.meta.resolve('vue'))}`);
const { default: Identity } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

test('the compact navy identity header keeps the actual portrait separate from decorative imagery', async () => {
    const html = await renderToString(createSSRApp(Identity, {
        record: { name: 'Applicant Example', educationSummary: 'Senior High School - Grade 12', photoUrl: '/dashboard/profile/photo' },
        status: { key: 'ready', label: 'Verified', tone: 'positive' },
    }));
    assert.match(html, /bg-portal-navy/);
    assert.match(html, /text-white[^>]*>Applicant Example/);
    assert.match(html, /src="\/dashboard\/profile\/photo"[^>]*alt="Applicant Example applicant photo"/);
    assert.match(html, /data-profile-cover aria-hidden="true"/);
    assert.match(html, /filipino-students-group-study\.jpg" alt(?:="")? width=/);
    assert.match(html, /Edit profile/);
    assert.match(html, /Verified/);
    assert.doesNotMatch(source, /min-h-\[|h-\[|h-screen/);
    assert.ok(existsSync(new URL('../../public/images/filipino-students-group-study.jpg', import.meta.url)));
});

test('a missing portrait still uses the avatar and never the decorative background', async () => {
    const html = await renderToString(createSSRApp(Identity, {
        record: { name: 'New Applicant', educationSummary: '', photoUrl: '' },
        status: { key: 'details_needed', label: 'Details needed', tone: 'attention' },
    }));
    assert.match(html, /default-applicant-avatar\.jpg/);
    assert.match(html, /Learning details not provided/);
    assert.match(html, /Details needed/);
});

test('shaded headings are opt-in on the applicant page, not a staff workspace restyle', () => {
    const details = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileDetails.vue', import.meta.url), 'utf8');
    const applicant = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const staff = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileRecord.vue', import.meta.url), 'utf8');
    assert.match(details, /tintedHeaders: \{ type: Boolean, default: false \}/);
    assert.match(applicant, /<ApplicantProfileDetails[^>]+tinted-headers/);
    assert.doesNotMatch(staff, /tinted-headers/);
    assert.match(details, /bg-slate-50/);
    assert.match(details, /data-profile-field-grid/);
});
