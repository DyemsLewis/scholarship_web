import { readFile } from 'node:fs/promises';

const url = new URL(process.env.STAFF_TABLE_SMOKE_URL ?? 'http://127.0.0.1:8018');
if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1') {
    throw new Error('The staff table smoke test only runs against a local demo server.');
}

let harness = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const hostedOrigin = "const origin = 'https://findscholarship.online';";
if (!harness.includes(hostedOrigin)) throw new Error('Cannot verify the browser harness origin.');
harness = harness.replace(hostedOrigin, `const origin = ${JSON.stringify(url.origin)};`)
    .replace('ACCOUNTABILITY_DEMO_PASSWORD', 'STAFF_TABLE_SMOKE_PASSWORD')
    .replace(/const folder = .*?;/, "const folder = 'storage/deployment/staff-tables-local';")
    .replaceAll('accountability-hosted-part6', 'staff-tables-local')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'staff-tables-local/report.json')
    .replace('let control, client, contextId;', 'let control, client, contextId; const fixtures = new Map();')
    .replace('const route = new URL(request.url).pathname;', String.raw`
            const route = new URL(request.url).pathname;
            if (request.method === 'GET' && fixtures.has(route)) {
                const body = Buffer.from(JSON.stringify(fixtures.get(route))).toString('base64');
                void client.send('Fetch.fulfillRequest', {
                    requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }], body,
                }).catch(() => {});
                return;
            }
    `);
const start = harness.indexOf("    const response = await fetch(origin + '/login');");
const end = harness.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');

const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const assets = [manifest['resources/js/app.js'].file, manifest['resources/css/app.css'].file];
harness = harness.slice(0, start) + `const expectedAssets = ${JSON.stringify(assets)};\n` + String.raw`
    const response = await fetch(origin + '/login');
    assert.equal(response.status, 200);
    const html = await response.text();
    for (const asset of expectedAssets) assert.ok(html.includes(asset), 'Local site uses built asset: ' + asset);
    report.pages = [];

    async function desktop(width) {
        await client.send('Emulation.setDeviceMetricsOverride', { width, height: 960, deviceScaleFactor: 1, mobile: false });
    }

    async function measure() {
        const probe = () => {
            const tables = [...document.querySelectorAll('.staff-table-viewport')];
            const rows = tables.flatMap(t => [...t.querySelectorAll('tbody tr')]).filter(row => row.cells.length > 1);
            const actions = rows.flatMap(row => [...row.querySelectorAll('.staff-row-action')]);
            const contentOverflows = rows.flatMap(row => [...row.cells]).filter(cell => cell.scrollWidth > cell.clientWidth + 2);
            return {
                pageOverflow: document.documentElement.scrollWidth > innerWidth + 2,
                rows: rows.length,
                actionCount: actions.length,
                overflowText: contentOverflows.map(cell => cell.innerText.slice(0, 85)),
                aligned: rows.every(row => [...row.cells].every(cell => getComputedStyle(cell).verticalAlign === 'middle')),
                styled: actions.every(action => ['flex', 'inline-flex'].includes(getComputedStyle(action).display) && parseFloat(getComputedStyle(action).borderRadius) >= 4),
                viewports: tables.map(table => ({
                    min: parseFloat(getComputedStyle(table).minHeight),
                    height: table.clientHeight,
                    tableHeight: table.querySelector('table')?.clientHeight ?? 0,
                })),
            };
        };
        return client.evaluate('(' + probe.toString() + ')()');
    }

    async function visit(route, { selector = 'h1', requireRows = false, widths = [1440, 1280] } = {}) {
        await desktop(widths[0]);
        const pathname = new URL(route, origin).pathname;
        await navigate(route, 'location.pathname === ' + JSON.stringify(pathname)
            + " && document.readyState === 'complete' && !!document.querySelector(" + JSON.stringify(selector) + ')');
        await wait(() => client.evaluate("!document.querySelector('.fa-spinner.fa-spin, .staff-table-viewport[aria-busy=true], .admin-table-frame[aria-busy=true]')"), 'data on ' + route);
        assert.equal(await client.evaluate("document.querySelector('[data-staff-table-load-error], .admin-table-frame [role=alert]')?.innerText || ''"), '', route + ' loaded');
        const title = await client.evaluate("document.querySelector('h1')?.innerText");
        const counts = [];
        for (const width of widths) {
            await desktop(width);
            const state = await measure();
            assert.equal(state.pageOverflow, false, route + ' overflow at ' + width);
            assert.deepEqual(state.overflowText, [], route + ' clipping at ' + width);
            assert.ok(state.aligned, route + ' row alignment');
            assert.ok(state.styled, route + ' shared row actions');
            if (requireRows) assert.ok(state.rows > 0, route + ' has rows');
            counts.push(state.rows);
        }
        report.pages.push({ route, title, widths, rows: counts[0] });
        console.log('CHECKED', route, counts[0], 'rows');
        return measure();
    }

    async function openRow(selector, targetSelector) {
        const query = 'document.querySelector(' + JSON.stringify(selector) + ')';
        const target = await client.evaluate(query + '?.href');
        assert.ok(target, 'Record action exists: ' + selector);
        const targetUrl = new URL(target);
        await client.evaluate(query + '.click()');
        await wait(() => client.evaluate('location.origin === ' + JSON.stringify(targetUrl.origin)
            + ' && [location.pathname, window.portalPathname?.()].includes(' + JSON.stringify(targetUrl.pathname) + ')'
            + " && document.readyState === 'complete' && !!document.querySelector(" + JSON.stringify(targetSelector) + ')'), 'record opened');
        return targetUrl;
    }

    async function tableShot(name, selector = '.staff-table-viewport') {
        await desktop(1440);
        await client.evaluate('document.querySelector(' + JSON.stringify(selector) + ").scrollIntoView({block:'center'})");
        await shot(name);
    }

    await login('marilag.manager');
    for (const route of [
        '/provider/programs/published',
        '/provider/workspaces/reviews/assigned', '/provider/workspaces/reviews/unassigned',
        '/provider/workspaces/selection/active', '/provider/workspaces/selection/results',
        '/provider/workspaces/decisions/pending',
        '/provider/workspaces/recipients/active', '/provider/workspaces/recipients/agreements',
        '/provider/workspaces/releases/record', '/provider/workspaces/releases/history', '/provider/workspaces/releases/upcoming',
        '/provider/workspaces/team/active', '/provider/workspaces/team/setup',
        '/provider/workspaces/support/needs-response', '/provider/workspaces/billing/active',
        '/provider/workspaces/organization-profile/readiness', '/provider/workspaces/organization-profile/verification',
    ]) await visit(route);

    await login('marilag.reviewer');
    await visit('/provider/workspaces/reviews/assigned', { requireRows: true });
    assert.doesNotMatch(await client.evaluate("document.querySelector('tbody').innerText"), /0 days waiting/);
    await wait(() => client.evaluate("!!document.querySelector('tbody img')?.naturalWidth"), 'review applicant photo or silhouette');
    assert.ok(await client.evaluate("document.querySelector('tbody img').getBoundingClientRect().height") >= 32);
    await tableShot('provider-review-queue');
    await openRow('tbody a.staff-row-action', '#application-review-title');
    report.providerReviewOpened = true;

    await login('marilag.monitoring');
    await visit('/provider/workspaces/monitoring/review', { requireRows: true });
    assert.match(await client.evaluate("document.querySelector('thead').innerText"), /Workload/i);
    await tableShot('provider-monitoring-queue');
    await openRow('tbody a.staff-row-action', '#monitoring-record-title');
    report.providerCheckInOpened = true;
    await visit('/provider/workspaces/monitoring/awaiting');
    await visit('/provider/workspaces/monitoring/history');

    await login('platform.reviews');
    for (const queue of ['providers', 'programs', 'applicants', 'selection-activities', 'benefits', 'monitoring']) {
        await visit('/admin/workspaces/reviews/' + queue + '?status=all', { requireRows: true });
    }
    const providersPath = '/admin/workspaces/reviews/providers';
    const providers = await get(providersPath + '/data?status=all');
    assert.equal(providers.status, 200);
    const provider = providers.body.items.find(item => item.proof_count > 0);
    assert.ok(provider, 'Demo provider with proof');
    const term = provider.name.split(/\s+/)[0];
    await visit(providersPath + '?status=all&search=' + encodeURIComponent(term), { requireRows: true });
    const filtered = await measure();
    assert.equal(filtered.rows, 1);
    assert.ok(filtered.viewports.some(viewport => viewport.min >= 384 && viewport.height >= viewport.min));
    await tableShot('admin-one-row-review-queue');
    await openRow('tbody a.staff-row-action', '[data-review-provider-document]');
    assert.ok((await measure()).viewports.some(viewport => viewport.min === 0 && viewport.height - viewport.tableHeight < 24));
    await client.evaluate("document.querySelector('[data-review-provider-document]').click()");
    await wait(() => client.evaluate("!!document.querySelector('[data-provider-document-review]')"), 'document review dialog');
    const dialog = await client.evaluate("document.querySelector('[data-provider-document-review]')?.innerText");
    assert.match(dialog, /document|proof/i);
    report.adminProofDialogOpened = true;
    await visit(providersPath, { requireRows: true });
    assert.equal(await client.evaluate("document.querySelector('input[type=search]').value"), term);
    assert.equal(await client.evaluate("document.querySelector('select[aria-label=\"Review status\"]').value"), 'all');
    report.queueFiltersRestored = true;
    await visit(providersPath + '?status=all&search=does-not-match-staff-table-smoke');
    assert.equal((await measure()).rows, 0);
    assert.match(await client.evaluate("document.querySelector('.staff-table-viewport').innerText"), /No provider records/);
    report.emptyQueue = true;

    await login('platform.accounts');
    await visit('/admin/workspaces/accounts/directory', { requireRows: true });
    await visit('/admin/workspaces/accounts/attention');
    await login('platform.billing');
    for (const queue of ['ready', 'active', 'waiting', 'completed']) await visit('/admin/workspaces/billing/queues/' + queue);
    await login('platform.support');
    await visit('/admin/workspaces/support/needs-response');
    await visit('/admin/workspaces/support/resolved');
    await login('platform.records');
    await visit('/admin/workspaces/records/activity', { requireRows: true });
    await visit('/admin/workspaces/records/exports');
    await login('platform.finance');
    await visit('/admin/workspaces/finance/payments');
    await visit('/admin/workspaces/finance/exceptions');

    const token = 'ReferenceDetail'.repeat(25);
    fixtures.set('/admin/workspaces/finance/exceptions/data', {
        items: [{ id: 1, reference_number: 'SMOKE-01', provider: 'LongProviderName'.repeat(9),
            provider_email: 'provider-contact-name'.repeat(5) + '@example.test',
            service: 'Application cycle support', status: 'failed', currency: 'PHP', amount: 10000,
            reason: 'The payment gateway returned ' + token, created_at: 'Nov 08, 2026' }],
        pagination: { total: 1, current_page: 1, last_page: 1, from: 1, to: 1 },
    });
    await visit('/admin/workspaces/finance/exceptions', { requireRows: true });
    assert.doesNotMatch(await client.evaluate("document.querySelector('tbody').innerText"), /ReferenceDetail/);
    await client.evaluate("document.querySelector('tbody summary').click()");
    assert.match(await client.evaluate("document.querySelector('tbody details[open]').innerText"), /ReferenceDetail/);
    assert.deepEqual((await measure()).overflowText, []);
    fixtures.clear();
    report.longTextDisclosure = true;

    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS local admin and provider table checks. No business actions submitted.');
` + harness.slice(end);

try { await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
