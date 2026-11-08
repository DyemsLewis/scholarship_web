import { readFile } from 'node:fs/promises';

let harness = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
harness = harness.replaceAll('accountability-hosted-part6', 'staff-tables-hosted')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'staff-tables-hosted-browser-20261108.json');
const start = harness.indexOf("    const response = await fetch(origin + '/login');");
const end = harness.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Hosted browser harness structure changed.');
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const expectedScript = manifest['resources/js/app.js'].file;
const expectedStyle = manifest['resources/css/app.css'].file;
harness = harness.slice(0, start) + `const expectedAssets = ${JSON.stringify([expectedScript, expectedStyle])};\n` + String.raw`
    const response = await fetch(origin + '/login');
    assert.equal(response.status, 200);
    assert.match(response.headers.get('cache-control'), /no-store/);
    assert.ok(response.headers.get('strict-transport-security'));
    const html = await response.text();
    for (const asset of expectedAssets) {
        assert.ok(html.includes(asset), 'The live page uses ' + asset);
        assert.equal((await fetch(origin + '/build/' + asset, { method: 'HEAD' })).status, 200);
    }
    report.latestBuild = true;

    await login('marilag.reviewer');
    const queues = [['mine', 'assigned'], ['unassigned', 'unassigned'], ['history', 'history'], ['returned', 'returned']];
    let reviewPage;
    for (const [key, tab] of queues) {
        const data = await get('/provider/workspaces/reviews/data?queue=' + key);
        assert.equal(data.status, 200);
        if (data.body.applications.length) { reviewPage = tab; break; }
    }
    assert.ok(reviewPage, 'At least one demo application in a review queue');
    await navigate('/provider/workspaces/reviews/' + reviewPage, "!!document.querySelector('tbody .staff-row-action')");
    assert.match(await client.evaluate("document.querySelector('thead').innerText"), /saved match.*advisory only/is);
    const providerTable = await client.evaluate("(()=>{const t=document.querySelector('.staff-table-viewport'),a=t.querySelector('tbody .staff-row-action');return {height:t.offsetHeight,label:a.getAttribute('aria-label'),display:getComputedStyle(a).display}})()");
    assert.ok(providerTable.height >= 384);
    assert.match(providerTable.label, /Open review for|Claim review for/);
    assert.equal(providerTable.display, 'inline-flex');
    await wait(() => client.evaluate("!!document.querySelector('tbody img')?.naturalWidth"), 'loaded profile image or placeholder');
    await shot('provider-review-queue');
    report.providerQueue = true;

    await login('platform.reviews');
    const providersPath = '/admin/workspaces/reviews/providers';
    const providers = await get(providersPath + '/data?status=all');
    assert.equal(providers.status, 200);
    const provider = providers.body.items.find((item) => /dalisay/i.test(item.name) && item.proof_count > 0)
        ?? providers.body.items.find((item) => item.proof_count > 0);
    assert.ok(provider?.review_url, 'Demo provider with proof');
    const query = provider.name.split(/\s+/)[0];
    await navigate(providersPath + '?status=all&search=' + encodeURIComponent(query), "!!document.querySelector('tbody .staff-row-action')");
    const link = await client.evaluate("[...document.querySelectorAll('tbody a.staff-row-action')].find(a=>a.getAttribute('aria-label')===" + JSON.stringify('Open ' + provider.name) + ")?.href");
    const review = new URL(link);
    assert.equal(review.origin, origin);
    assert.equal(review.searchParams.get('return_to'), providersPath);
    await navigate(review.pathname + review.search, "!!document.querySelector('.staff-table-viewport--compact [data-review-provider-document]')");
    const proofTable = await client.evaluate("(()=>{const t=document.querySelector('.staff-table-viewport--compact'),a=t.querySelector('[data-review-provider-document]');return {min:getComputedStyle(t).minHeight,height:t.offsetHeight,tableHeight:t.querySelector('table').offsetHeight,label:a.getAttribute('aria-label')}})()");
    assert.equal(proofTable.min, '0px');
    assert.ok(proofTable.height - proofTable.tableHeight < 24);
    assert.match(proofTable.label, /^Review /);
    await client.evaluate("document.querySelector('.staff-table-viewport--compact').parentElement.scrollIntoView({block:'center'})");
    await shot('admin-compact-proof-table');
    report.compactProofTable = true;

    const back = await client.evaluate("[...document.querySelectorAll('a')].find(a=>a.innerText.trim()==='Provider queue')?.getAttribute('href')");
    assert.equal(back, providersPath);
    await navigate(back, "!!document.querySelector('tbody .staff-row-action')");
    assert.equal(await client.evaluate("document.querySelector('input[type=search]').value"), query);
    assert.equal(await client.evaluate("[...document.querySelectorAll('select')].find(e=>e.getAttribute('aria-label')==='Review status').value"), 'all');
    report.filtersPreserved = true;
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted review tables, labels, images, compact height and restored filters. No review actions submitted.');
` + harness.slice(end);

try { await import('data:text/javascript;base64,' + Buffer.from(harness).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
