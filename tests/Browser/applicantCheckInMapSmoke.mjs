import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const origin = process.env.CHECK_IN_MAP_ORIGIN || 'https://findscholarship.online';
const output = (process.env.CHECK_IN_MAP_OUTPUT || 'storage/qa/check-in-map-20261113').replaceAll('\\', '/');
source = source.replace(/^const origin = .*;$/m, `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(output)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', output + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    await login('demo.student.monitoring.appointment');
    const ok = async (url) => { const response = await get(url); assert.equal(response.status, 200, url); return response.body; };
    const support = (await ok('/dashboard/monitoring/data')).support;
    const award = [...support.active, ...support.past].find(row => row.href);
    assert.ok(award, 'An existing scholarship support record is required.');
    const recordPath = new URL(award.href, origin).pathname;
    const detail = (await ok(recordPath + '/data?view=check-ins')).detail;
    const appointment = detail.content.directory.next_appointment;
    assert.ok(appointment?.handoff?.location, 'An existing check-in venue is required.');
    const modal = '[role=dialog][aria-labelledby=location-map-modal-title]';
    const viewport = async (width, height) => client.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 });
    const openMap = async (scope) => {
        await client.evaluate('(()=>{const b=[...' + scope + '.querySelectorAll("button")].find(b=>b.innerText.trim()==="View map");b.focus()})()');
        await click('View map', scope);
        await wait(() => client.evaluate('!!document.querySelector(' + JSON.stringify(modal) + ')'), 'check-in map modal');
        const result = await client.evaluate('(()=>{const d=document.querySelector(' + JSON.stringify(modal) + '), s=d.querySelector("section"), r=s.getBoundingClientRect(), a=[...d.querySelectorAll("a")].find(a=>a.innerText.includes("Open in Google Maps")); return {text:d.innerText,url:a?.href,focus:d.contains(document.activeElement),fits:r.left>=0&&r.top>=0&&r.right<=innerWidth+2&&r.bottom<=innerHeight+2,overflow:document.documentElement.scrollWidth>innerWidth+2}})()');
        assert.ok(result.text.includes(appointment.handoff.location));
        assert.equal(new URL(result.url).searchParams.get('query'), appointment.handoff.location);
        assert.equal(result.focus, true); assert.equal(result.fits, true); assert.equal(result.overflow, false);
    };
    const closeMap = async () => {
        await click('Close map', 'document.querySelector(' + JSON.stringify(modal) + ')');
        await wait(() => client.evaluate('!document.querySelector(' + JSON.stringify(modal) + ')'), 'closed map');
        assert.equal(await client.evaluate('document.activeElement?.innerText.trim()'), 'View map');
    };
    await navigate(recordPath + '/check-ins', '!!document.querySelector("[data-next-check-in-appointment]")');
    await openMap('document.querySelector("[data-next-check-in-appointment]")');
    await shot('check-in-directory-map-desktop');
    await closeMap();
    const detailUrl = new URL(appointment.href, origin);
    assert.equal(detailUrl.origin, origin);
    await navigate(detailUrl.pathname + detailUrl.search, '!!document.querySelector("[data-check-in-appointment]")');
    await openMap('document.querySelector("[data-check-in-appointment]")');
    await shot('check-in-detail-map-desktop');
    await client.evaluate('document.querySelector(' + JSON.stringify(modal) + ').dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}))');
    await wait(() => client.evaluate('!document.querySelector(' + JSON.stringify(modal) + ')'), 'Escape closes map');
    await viewport(390, 844);
    await openMap('document.querySelector("[data-check-in-appointment]")');
    await shot('check-in-detail-map-mobile');
    await closeMap();
    assert.equal(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), true);
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Check-in directory and record map actions, current venue, Google Maps URL, desktop/mobile modal bounds, Escape and focus return. No business writes submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
