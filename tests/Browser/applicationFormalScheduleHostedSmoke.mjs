import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/application-formal-schedule-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    await login('demo.student.formal');
    const list = await get('/dashboard/applications/data');
    assert.equal(list.status, 200);
    const application = list.body.applications[0]; assert.ok(application);
    const id = application.id; report.verified_application_ids.push(id);
    const response = await get('/dashboard/applications/' + id + '/data?view=activities');
    assert.equal(response.status, 200);
    const formal = response.body.detail.content.formal_application;
    assert.ok(formal?.deadline, 'Actual application handoff must be available.');
    await navigate('/dashboard/applications/' + id + '/activities', '!!document.querySelector("[data-application-formal-schedule]") && document.readyState==="complete"');
    assert.ok(await client.evaluate('document.querySelector("[data-formal-completion-deadline]").innerText.includes(' + JSON.stringify(formal.deadline) + ')'));
    assert.equal(await client.evaluate('document.querySelectorAll("[data-application-formal-schedule]").length'), 1);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1200, deviceScaleFactor: 1, mobile: false });
    await client.evaluate('document.querySelector("[data-formal-preparation]").open=true;window.scrollTo(0,0)');
    await shot('application-formal-schedule-desktop');
    await click('View map', 'document.querySelector("[data-application-formal-schedule]")');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog][aria-labelledby=location-map-modal-title]")'), 'application handoff map');
    await shot('application-formal-schedule-map');
    await click('Close map', 'document.querySelector("[role=dialog][aria-labelledby=location-map-modal-title]")');
    await wait(() => client.evaluate('!document.querySelector("[role=dialog][aria-labelledby=location-map-modal-title]")'), 'map closes');
    if (formal.contact?.email) {
        assert.equal(await client.evaluate('[...document.querySelectorAll("[data-formal-provider-contact] a")].find(a => a.getAttribute("href").startsWith("mailto:")).getAttribute("href")'), 'mailto:' + formal.contact.email);
    }
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await client.evaluate('document.querySelector("[data-application-formal-schedule]").scrollIntoView({block:"start"});window.scrollBy(0,-12)');
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await shot('application-formal-schedule-mobile');
    const scholarship = await get('/dashboard/scholarships/2/data?section=application');
    assert.equal(scholarship.status, 200);
    assert.equal(scholarship.body.detail.content.process.formal_application.deadline_label, formal.deadline, 'Both views retain the published deadline.');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true;
    console.log('PASS Hosted application-record formal stage: real deadline, venue map, preparation, contacts and responsive layout. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
