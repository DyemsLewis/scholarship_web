import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/eligibility-quick-scan-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    await login('demo.student.apply');
    await navigate('/dashboard/scholarships/1/eligibility', '!!document.querySelector("[data-eligibility-values]") && document.readyState==="complete"');
    const response = await get('/dashboard/scholarships/1/data?section=eligibility'); assert.equal(response.status, 200);
    const criteria = response.body.detail.content.criteria.filter(c=>c.category!=='readiness'&&c.key!=='documents');
    const rendered = await client.evaluate('document.querySelectorAll("[data-eligibility-criterion]").length+document.querySelectorAll("[data-unrestricted-criteria] li").length');
    assert.equal(rendered, criteria.length, 'No eligibility criterion is lost.');
    assert.equal(await client.evaluate('document.querySelector("[data-eligibility-group=matched]").open'), false);
    assert.equal(await client.evaluate('[...document.querySelectorAll("[data-eligibility-explanation]")].every(d=>!d.open)'), true);
    assert.equal(await client.evaluate('[...document.querySelectorAll("[data-eligibility-values]")].filter(e=>e.children.length===2).every(e=>{const a=e.children[0].getBoundingClientRect(),b=e.children[1].getBoundingClientRect();return !a.width||b.top>=a.bottom-1})'), true, 'Program and profile values are stacked.');
    await client.evaluate('document.querySelector("[data-scholarship-eligibility]").scrollIntoView({block:"start"});window.scrollBy(0,-80)');
    await shot('eligibility-summary-desktop');
    await client.evaluate('document.querySelector("[data-eligibility-group=matched]").open=true');
    await delay(400);
    await client.evaluate('document.querySelector("[data-scholarship-eligibility]").scrollIntoView({block:"start"});window.scrollBy(0,-16)');
    await shot('eligibility-rules-desktop');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await client.evaluate('document.querySelector("[data-scholarship-eligibility]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await shot('eligibility-mobile');
    await client.evaluate('document.querySelector("[data-eligibility-group=matched]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await shot('eligibility-matched-mobile');
    await login('talunaw.programs');
    await navigate('/provider/previews/programs/1/eligibility?scenario=general', '!!document.querySelector("[data-eligibility-group=required]") && document.readyState==="complete"');
    assert.equal(await client.evaluate('document.querySelectorAll("[data-eligibility-result]").length'), 0);
    assert.equal(await client.evaluate('[...document.querySelectorAll("[data-scholarship-eligibility] a")].some(a=>a.getAttribute("href")?.startsWith("/dashboard/profile"))'), false);
    await client.evaluate('document.querySelector("[data-scholarship-eligibility]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    await shot('eligibility-provider-preview');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true;
    console.log('PASS Hosted scan-first eligibility, preserved criteria, expandable comparisons, mobile containment and read-only provider preview. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
