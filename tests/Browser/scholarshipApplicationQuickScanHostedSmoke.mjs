import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/how-to-apply-quick-scan-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    await login('demo.student.apply');
    await navigate('/dashboard/scholarships/1/application', '!!document.querySelector("[data-application-instructions]") && document.readyState==="complete"');
    const api = await get('/dashboard/scholarships/1/data?section=application'); assert.equal(api.status, 200);
    const files = api.body.detail.content.requirements.readiness.items;
    assert.equal(await client.evaluate('document.querySelectorAll("[data-application-instruction]").length'), 4);
    assert.equal(await client.evaluate('document.querySelectorAll("[data-required-file]").length'), files.length);
    assert.equal(await client.evaluate('document.querySelector("[data-application-instructions]").innerText.trim().split(/\s+/).length<90'), true);
    assert.equal(await client.evaluate('(()=>{const p=document.querySelector("[data-scholarship-how-to-apply]"),a=p.children[0].getBoundingClientRect(),b=p.children[1].getBoundingClientRect();return b.top>=a.bottom-1&&Math.abs(a.width-b.width)<2})()'), true, 'Instructions and checklist remain one column.');
    assert.equal(await client.evaluate('!!document.querySelector("[data-guide-submission-note]")&&!document.querySelector("[data-guide-submission-note]").open'), true);
    assert.equal(await client.evaluate('[...document.querySelectorAll("[data-file-guidance],[data-provider-questions],[data-optional-files],[data-later-files]")].every(d=>!d.open)'), true);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1500, deviceScaleFactor: 1, mobile: false });
    await client.evaluate('document.querySelector("[data-scholarship-how-to-apply]").scrollIntoView({block:"start"});window.scrollBy(0,-16)');
    await shot('application-instructions-checklist-desktop');
    await client.evaluate('document.querySelector("[data-guide-submission-note]").open=true');
    assert.ok(await client.evaluate('document.querySelector("[data-guide-submission-note]").innerText.includes("document upload terms")'));
    await client.evaluate('document.querySelector("[data-guide-submission-note]").open=false');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await client.evaluate('document.querySelector("[data-scholarship-application-guide]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await shot('application-instructions-mobile');
    await client.evaluate('document.querySelector("[data-scholarship-application-files]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await shot('before-you-submit-mobile');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true;
    console.log('PASS Hosted concise instructions, visible file statuses, expandable tips and single-column desktop/mobile layouts. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
