import assert from 'node:assert/strict';
import test from 'node:test';

import {
    isStaffFilePreviewUrl,
    isStaffWorkspacePath,
    shouldPreviewStaffFileClick,
} from '../../resources/js/support/staffFilePreview.js';

const providerPage = 'http://localhost/provider/applications/12';
const adminPage = 'http://localhost/admin/workspaces/reviews/applicants/7';

test('staff workspace detection is limited to provider and admin portals', () => {
    assert.equal(isStaffWorkspacePath('/provider/workspaces/reviews'), true);
    assert.equal(isStaffWorkspacePath('/admin/workspaces/support'), true);
    assert.equal(isStaffWorkspacePath('/dashboard/documents'), false);
    assert.equal(isStaffWorkspacePath('/'), false);
});

test('provider file routes open in the shared preview', () => {
    const urls = [
        '/documents/8/view',
        '/service-files/4/view',
        '/provider/verification-documents/2/view',
        '/provider/applications/12/profile-proofs/3/view',
        '/provider/reports/5/attachment',
        '/provider/monitoring-submissions/9/view',
        '/provider/monitoring-adjustment-requests/6/attachment',
        '/provider/benefit-release-records/10/receipt',
        '/provider/benefit-receipt-responses/11/files/evidence',
        '/provider/support-decisions/13/files/resolution-proof',
    ];

    for (const url of urls) {
        assert.equal(isStaffFilePreviewUrl(url, providerPage), true, url);
    }
});

test('admin file routes open in the shared preview', () => {
    const urls = [
        '/documents/8/view',
        '/service-files/4/view',
        '/admin/reports/5/attachment',
        '/admin/applicant-verification-documents/3/view',
        '/admin/provider-verification-documents/2/view',
        '/admin/workspaces/reviews/selection-activities/evidence/14/view',
        '/admin/monitoring-submissions/9/view',
        '/admin/monitoring-adjustments/6/attachment',
        '/admin/benefit-release-records/10/receipt',
        '/admin/benefit-receipt-responses/11/files/recipient-evidence',
        '/admin/support-decisions/13/files/applicant-response',
    ];

    for (const url of urls) {
        assert.equal(isStaffFilePreviewUrl(url, adminPage), true, url);
    }
});

test('downloads, records, external files, and applicant pages are not intercepted', () => {
    assert.equal(isStaffFilePreviewUrl('/documents/8/download', providerPage), false);
    assert.equal(isStaffFilePreviewUrl('/provider/applications/12', providerPage), false);
    assert.equal(isStaffFilePreviewUrl('/admin/workspaces/reviews/applicants/7', adminPage), false);
    assert.equal(isStaffFilePreviewUrl('https://example.com/file.pdf', providerPage), false);
    assert.equal(isStaffFilePreviewUrl('/documents/8/view', 'http://localhost/dashboard/documents'), false);
});

test('only an ordinary primary click opens a matched file route', () => {
    const anchor = {
        href: 'http://localhost/documents/8/view',
        dataset: {},
        hasAttribute: () => false,
    };
    const click = {
        defaultPrevented: false,
        button: 0,
        metaKey: false,
        ctrlKey: false,
        shiftKey: false,
        altKey: false,
    };

    assert.equal(shouldPreviewStaffFileClick(click, anchor, providerPage), true);
    assert.equal(shouldPreviewStaffFileClick({ ...click, ctrlKey: true }, anchor, providerPage), false);
    assert.equal(shouldPreviewStaffFileClick({ ...click, button: 1 }, anchor, providerPage), false);
    assert.equal(shouldPreviewStaffFileClick(click, { ...anchor, hasAttribute: () => true }, providerPage), false);
});
