import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/how-to-apply-single-column-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    await login('demo.student.apply');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1200, deviceScaleFactor: 1, mobile: false });
    const response = await get('/dashboard/scholarships/1/data?section=application');
    assert.equal(response.status, 200);
    const formal = response.body.detail.content.process.formal_application;
    assert.ok(formal?.deadline_label, 'Published formal deadline must be available.');
    assert.equal(formal.is_assigned_appointment, false);
    await navigate('/dashboard/scholarships/1/application', '!!document.querySelector("[data-scholarship-formal-schedule]") && document.readyState==="complete"');
    assert.equal(await client.evaluate('document.querySelector("[data-formal-deadline]").innerText.trim()'), formal.deadline_label);
    assert.equal(await client.evaluate('(()=>{const e=document.querySelector("[data-scholarship-how-to-apply]"),a=e.children[0].getBoundingClientRect(),b=e.children[1].getBoundingClientRect();return b.top>=a.bottom-1&&Math.abs(a.width-b.width)<2})()'), true, 'Guide and requirements stack.');
    assert.equal(await client.evaluate('(()=>{const d=document.querySelector("[data-formal-deadline-strip]").getBoundingClientRect(),v=document.querySelector("[data-formal-venue]").getBoundingClientRect();return v.top>=d.bottom-1&&Math.abs(d.width-v.width)<2})()'), true, 'Deadline and instructions are full-width rows.');
    await client.evaluate('document.querySelector("[data-formal-preparation]").open=true;document.querySelector("[data-scholarship-application-process]").scrollIntoView({block:"start"});window.scrollBy(0,-16)');
    await shot('how-to-apply-formal-schedule-desktop');
    await click('View map', 'document.querySelector("[data-scholarship-formal-schedule]")');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog][aria-labelledby=location-map-modal-title]")'), 'published formal venue map');
    await click('Close map', 'document.querySelector("[role=dialog][aria-labelledby=location-map-modal-title]")');
    await wait(() => client.evaluate('!document.querySelector("[role=dialog][aria-labelledby=location-map-modal-title]")'), 'map closes');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await client.evaluate('document.querySelector("[data-scholarship-formal-schedule]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await shot('how-to-apply-formal-schedule-mobile');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true;
    console.log('PASS Hosted single-column How to apply, deadline rows, venue map and mobile layout. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
