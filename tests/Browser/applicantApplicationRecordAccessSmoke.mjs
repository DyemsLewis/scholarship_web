import { readFile } from 'node:fs/promises';

const origin = process.env.APPLICATION_RECORD_ORIGIN || 'https://findscholarship.online';
const output = (process.env.APPLICATION_RECORD_OUTPUT || 'storage/qa/application-record-access-20261113').replaceAll('\\', '/');
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const origin = .*;$/m, `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', output + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    for (const [index, username] of ['demo.student.monitoring.appointment', 'demo.student.onetime.confirm'].entries()) {
        await login(username);
        const response = await get('/dashboard/applications/data');
        assert.equal(response.status, 200);
        const award = response.body.applications.find(application => application.recipient_agreement?.status === 'accepted');
        assert.ok(award, 'An existing accepted scholarship is required.');
        await navigate('/dashboard/applications', '!!document.querySelector("[data-application-record-link]") && document.readyState==="complete"');
        assert.equal(await client.evaluate('document.querySelector("[data-application-record-link]").innerText.trim()'), 'View application');
        assert.equal(await client.evaluate('document.querySelectorAll("[data-application-support-link]").length'), 1);
        if (index === 0) await shot('accepted-application-separate-links');
        await client.evaluate('document.querySelector("[data-application-record-link]").click()');
        await wait(() => client.evaluate('!!document.querySelector("[data-application-detail-header]") && document.readyState==="complete"'), 'accepted application record opens');
        assert.equal(await client.evaluate('document.getElementById("app").dataset.applicationSection'), 'summary');
        assert.equal(await client.evaluate('!!document.querySelector("[data-monitoring-detail-header]")'), false);
        const detailResponse = await get('/dashboard/applications/' + award.id + '/data');
        assert.equal(detailResponse.status, 200);
        for (const section of detailResponse.body.detail.navigation.filter(item => item.key !== 'summary')) {
            const url = new URL(section.href, origin);
            assert.equal(url.origin, origin);
            await navigate(url.pathname + url.search, '!!document.querySelector("[data-application-detail-header]") && document.getElementById("app").dataset.applicationSection===' + JSON.stringify(section.key) + ' && document.readyState==="complete"');
        }
        if (index === 0) await shot('accepted-application-record-history');
        await navigate('/dashboard/applications', '!!document.querySelector("[data-application-support-link]")');
        await client.evaluate('document.querySelector("[data-application-support-link]").click()');
        await wait(() => client.evaluate('!!document.querySelector("[data-monitoring-detail-header]")'), 'separate support link opens');
        await client.evaluate('[...document.querySelectorAll("a")].find(a=>a.innerText.trim().startsWith("Application record")).click()');
        await wait(() => client.evaluate('!!document.querySelector("[data-application-detail-header]") && document.readyState==="complete"'), 'support links back to application');
        if (index === 0) {
            await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
            await navigate('/dashboard/applications', '!!document.querySelector("[data-application-record-link]")');
            assert.equal(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), true);
            await shot('accepted-application-links-mobile');
        }
        console.log('VERIFIED accepted application and support remain separately accessible: ' + username);
    }
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Accepted recurring and one-time award application links, all record tabs, separate Scholarship Support access, return link, and mobile layout. No business writes submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
