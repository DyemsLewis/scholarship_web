import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/support-location-fix-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    const ok = async url => { const r=await get(url);assert.equal(r.status,200,url);return r.body; };
    const openMap = async (scope) => {
        await click('View map', scope);
        await wait(() => client.evaluate('!!document.querySelector("[aria-labelledby=location-map-modal-title] .leaflet-marker-icon")'), 'pinned map marker');
        const href = await client.evaluate('[...document.querySelectorAll("[aria-labelledby=location-map-modal-title] a")].find(a=>a.innerText.includes("Open in Google Maps"))?.href');
        assert.equal(new URL(href).searchParams.get('query'),'14.5864,121.1753');
    };
    const closeMap = async () => {
        await client.evaluate('document.querySelector("[aria-labelledby=location-map-modal-title] button[aria-label="+JSON.stringify("Close map")+"]").click()');
        await wait(() => client.evaluate('!document.querySelector("[aria-labelledby=location-map-modal-title]")'), 'map closed');
    };
    await login('demo.student.monitoring.appointment');
    const list = (await ok('/dashboard/monitoring/data')).support;
    const award=[...list.active,...list.past].find(a=>a.href); assert.ok(award);
    const base=new URL(award.href,origin).pathname;
    const checks=(await ok(base+'/data?view=check-ins')).detail;
    const appointment=checks.content.directory.next_appointment; assert.ok(appointment);
    assert.equal(Number(appointment.handoff.latitude),14.5864);
    assert.equal(Number(appointment.handoff.longitude),121.1753);
    await navigate(new URL(appointment.href,origin).pathname,'!!document.querySelector("[data-check-in-appointment]")');
    await openMap('document.querySelector("[data-check-in-appointment]")');
    await shot('applicant-check-in-pinned-map'); await closeMap();
    const benefits=(await ok(base+'/data?view=benefits')).detail;
    const supplies=Object.values(benefits.content.directory).flat().find(r=>r&&/school supplies kit/i.test(r.title)); assert.ok(supplies);
    await navigate(new URL(supplies.href,origin).pathname,'!!document.querySelector("[data-benefit-release-record]")');
    const text=await client.evaluate('document.querySelector("[data-benefit-release-record]").innerText');
    assert.ok(text.includes('In person')||text.includes('In-person')||text.includes('In Person'));
    assert.ok(text.includes('Collect your school supplies kit')); assert.ok(text.includes('Provider instructions'));
    assert.ok(!/mentoring/i.test(text));
    await shot('applicant-school-supplies-instructions');
    await openMap('document.querySelector("[data-benefit-delivery-details]")');
    await closeMap();
    for(const user of ['demo.student.payout.details','demo.student.payout.review']) {
        await login(user);
        const directory=(await ok('/dashboard/monitoring/data')).support;
        const active=[...directory.active,...directory.past].find(a=>a.href); assert.ok(active);
        const path=new URL(active.href,origin).pathname;
        const releases=(await ok(path+'/data?view=benefits')).detail;
        const release=Object.values(releases.content.directory).flat().find(r=>r?.href);assert.ok(release);
        await navigate(new URL(release.href,origin).pathname,'!!document.querySelector("[data-benefit-release-record]")');
        const content=await client.evaluate('document.querySelector("[data-benefit-release-record]").innerText');
        assert.ok(content.includes('Provider instructions')); assert.ok(content.includes('outside the portal'));
        assert.ok(!content.includes('Collection venue'));
        if(user.endsWith('details')) await shot('applicant-bank-transfer-instructions');
    }
    await login('talunaw.monitoring');
    await navigate('/provider/workspaces/monitoring/check-ins/1','document.body.innerText.toLowerCase().includes("original group appointment")');
    await openMap('document'); await shot('provider-check-in-pinned-map'); await closeMap();
    await login('talunaw.releases');
    await navigate('/provider/workspaces/releases/schedule','!!document.querySelector("[data-provider-venue-address]") && !!document.querySelector("textarea")');
    assert.ok(await client.evaluate('!!document.querySelector("textarea[placeholder*=delivered]")'));
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Hosted applicant/provider pinned maps, school-supplies instructions, bank/wallet guidance and in-person instructions editor. No business actions submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message);process.exitCode=1; }
