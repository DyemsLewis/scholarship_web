import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { labelFromKey } from '../../resources/js/support/display.js';

const source = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('machine keys are converted to readable labels', () => {
    assert.equal(labelFromKey('senior_high_school'), 'Senior High School');
    assert.equal(labelFromKey('formal_application'), 'Formal Application');
    assert.equal(labelFromKey('needs_replacement'), 'Needs Replacement');
    assert.equal(labelFromKey('provider_review'), 'Provider Review');
});

test('active workspaces do not render audited machine values directly', () => {
    const pages = [
        'resources/js/pages/AdminPage.vue',
        'resources/js/pages/AdminProgramReviewPage.vue',
        'resources/js/pages/admin/finance/FinanceExceptionsPage.vue',
        'resources/js/pages/admin/portal/PortalManagerAreaPage.vue',
        'resources/js/pages/admin/reviews/ApplicantReviewRecordPage.vue',
        'resources/js/pages/admin/reviews/SelectionActivityRecordPage.vue',
        'resources/js/pages/admin/governance/SuperAdminAreaPage.vue',
        'resources/js/pages/provider/team/TeamAccountFormPage.vue',
        'resources/js/pages/provider/organization/OrganizationProfileReadinessPage.vue',
        'resources/js/pages/provider/billing/BillingRequestDirectoryPage.vue',
        'resources/js/pages/provider/billing/BillingServiceIntakePage.vue',
        'resources/js/pages/provider/billing/components/BillingServiceWorkspaceShell.vue',
    ];

    const combined = pages.map(source).join('\n');

    assert.doesNotMatch(combined, /\{\{\s*user\.role\s*\}\}/);
    assert.doesNotMatch(combined, /\{\{\s*program\.status\s*\}\}/);
    assert.doesNotMatch(combined, /\{\{\s*document\.status\s*\}\}/);
    assert.doesNotMatch(combined, /\{\{\s*session\.mode\s*\}\}/);
    assert.doesNotMatch(combined, /\{\{\s*(?:entry|purchase|item)\.status\s*\}\}/);
    assert.doesNotMatch(combined, /\{\{\s*item\.(?:actor_role|category)\s*\}\}/);
});
