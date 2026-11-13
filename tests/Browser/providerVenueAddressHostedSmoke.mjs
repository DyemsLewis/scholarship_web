import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/venue-address-20261113';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end <= start) throw new Error('Browser harness changed.');
source = source.slice(0, start) + String.raw`
    await login('talunaw.manager');
    const setup = await get('/provider/workspaces/selection/sessions/create/data'); assert.equal(setup.status, 200);
    const program = setup.body.programs.find(p=>p.stages.some(s=>s.key==='exam')); assert.ok(program);
    await navigate('/provider/workspaces/selection/setup?create_session=1&program_id=' + program.id + '&stage_key=exam', '!!document.querySelector("[aria-labelledby=session-modal-title] input[placeholder]")');
    await client.evaluate('(()=>{const e=document.querySelector("[aria-labelledby=session-modal-title] input[type=text]");e.value="Venue setup check";e.dispatchEvent(new Event("input",{bubbles:true}))})()');
    await click('Continue', 'document.querySelector("[aria-labelledby=session-modal-title]")');
    await wait(() => client.evaluate('!!document.querySelector("[data-provider-venue-address]")'), 'structured session address');
    const field = (suffix, value) => client.evaluate('(()=>{const e=[...document.querySelectorAll("[data-provider-venue-address] input,[data-provider-venue-address] select")].find(e=>e.id.endsWith(' + JSON.stringify('-'+suffix) + '));if(!e)throw new Error("Missing address field");e.value=' + JSON.stringify(value) + ';e.dispatchEvent(new Event(e.tagName==="SELECT"?"change":"input",{bubbles:true}))})()');
    await field('venue', 'Student Services Office');
    await field('region', 'Region IV-A');
    await wait(() => client.evaluate('[...document.querySelectorAll("[data-provider-venue-address] select option")].some(o=>o.value==="Rizal") || !!document.querySelector("[data-provider-venue-address] input[id$=province]")'), 'province list or fallback');
    await field('province', 'Rizal');
    await wait(() => client.evaluate('[...document.querySelectorAll("[data-provider-venue-address] select option")].some(o=>/antipolo/i.test(o.value)) || !!document.querySelector("[data-provider-venue-address] input[id$=city]")'), 'city list or fallback');
    const cityValue = await client.evaluate('[...document.querySelectorAll("[data-provider-venue-address] select option")].find(o=>/antipolo/i.test(o.value))?.value || "Antipolo City"');
    await field('city', cityValue);
    await field('barangay', 'San Isidro');
    await field('street', 'School Road');
    assert.equal(await client.evaluate('document.querySelectorAll("[data-provider-venue-address] label").length'), 6);
    await client.evaluate('document.querySelector("[data-provider-venue-address]").scrollIntoView({block:"center"})');
    await shot('selection-session-address');
    await click('Set map pin', 'document.querySelector("[data-provider-venue-address]")');
    await wait(() => client.evaluate('!!document.querySelector("[aria-labelledby=location-map-modal-title]")'), 'venue pin picker');
    await client.evaluate('document.querySelector("[aria-labelledby=location-map-modal-title] button[aria-label="+JSON.stringify("Close map")+"]")?.click()');
    await wait(() => client.evaluate('!document.querySelector("[aria-labelledby=location-map-modal-title]")'), 'venue pin picker closed');
    await client.evaluate('(()=>{const e=[...document.querySelectorAll("[aria-labelledby=session-modal-title] select")].find(e=>[...e.options].some(o=>o.value==="online"));e.value="online";e.dispatchEvent(new Event("change",{bubbles:true}))})()');
    await wait(() => client.evaluate('!document.querySelector("[data-provider-venue-address]") && !!document.querySelector("[aria-labelledby=session-modal-title] input[type=url]")'), 'online delivery excludes address');
    await navigate('/provider/workspaces/releases/schedule', '!!document.querySelector("[data-provider-venue-address]") && document.readyState==="complete"');
    await client.evaluate('document.querySelector("[data-provider-venue-address]").scrollIntoView({block:"center"})');
    await shot('benefit-release-address');
    const schedule = await get('/provider/workspaces/releases/schedule/data'); assert.equal(schedule.status, 200);
    let active;
    for (const candidate of schedule.body.programs) {
        const plan = await get('/provider/scholarships/' + candidate.id + '/monitoring-plan');
        if(plan.status===200&&plan.body.plan?.status==='active'&&plan.body.appointment_recipients?.length){active=candidate;break;}
    }
    assert.ok(active, 'A demo active monitoring plan exists.');
    await navigate('/provider/monitoring/' + active.id + '/plan', 'document.body.innerText.includes("New check-in") && document.readyState==="complete"');
    await click('New check-in');
    await wait(() => client.evaluate('!!document.querySelector("[aria-labelledby=check-in-editor-title] [data-provider-venue-address]")'), 'check-in address editor');
    await client.evaluate('document.querySelector("[data-provider-venue-address]").scrollIntoView({block:"start"})');
    await shot('monitoring-check-in-address');
    await click('Cancel', 'document.querySelector("[aria-labelledby=check-in-editor-title]")');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true;
    console.log('PASS Hosted region/province/city address fields, venue pin modal, online-only delivery and unsaved check-in/release forms. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
