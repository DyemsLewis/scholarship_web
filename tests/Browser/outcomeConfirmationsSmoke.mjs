import { readFile } from 'node:fs/promises';

// Reuse the isolated Chrome harness; all mutations below use an in-memory fixture.
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `
const { createServer: createViteServer } = await import(${JSON.stringify(import.meta.resolve('vite'))});
const vue = (await import(${JSON.stringify(import.meta.resolve('@vitejs/plugin-vue'))})).default;
const tailwind = (await import(${JSON.stringify(import.meta.resolve('@tailwindcss/vite'))})).default;
const fixtureServer = await createViteServer({ configFile: false, plugins: [vue(), tailwind()], server: { host: '127.0.0.1', port: 0, hmr: false, watch: null }, logLevel: 'error' });
await fixtureServer.listen();
const origin = 'http://127.0.0.1:' + fixtureServer.httpServer.address().port;
`).replace('const password = process.env.ACCOUNTABILITY_DEMO_PASSWORD;', "const password = 'fixture-only';")
    .replaceAll('accountability-hosted-part6', 'outcome-confirmations')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'outcome-confirmations-browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    contextId = (await control.send('Target.createBrowserContext')).browserContextId;
    const target = await control.send('Target.createTarget', {url:'about:blank', browserContextId:contextId});
    client = new Cdp('ws://127.0.0.1:' + port + '/devtools/page/' + target.targetId); await configure();
    await navigate('/tests/Browser/outcomeConfirmations.fixture.html', '!!window.fixture && document.body.innerText.includes("Learning Grant") && !!document.querySelector("form.admin-panel textarea")');
    console.log('FIXTURE_READY');
    const input = (selector, value) => client.evaluate('(()=>{const e=document.querySelector(' + JSON.stringify(selector) + ');e.value=' + JSON.stringify(value) + ';e.dispatchEvent(new Event("input",{bubbles:true}))})()');
    const count = () => client.evaluate('fixture.writes.length');
    const confirmScope = 'document.querySelector("[data-outcome-confirmation]")';
    const visiblePrompt = () => wait(() => client.evaluate('!!document.querySelector("[data-outcome-confirmation]")'), 'outcome confirmation');
    const closedPrompt = () => wait(() => client.evaluate('!document.querySelector("[data-outcome-confirmation]")'), 'closed confirmation');
    const escape = async () => { await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'}); await client.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape'}); };
    await input('form.admin-panel textarea', 'The publication checks are complete.');
    await client.evaluate('document.querySelector("form.admin-panel").scrollIntoView({block:"center"})');
    const before = await client.evaluate('({x:document.querySelector("form.admin-panel").getBoundingClientRect().x,y:scrollY})');
    await click('Save decision'); await visiblePrompt(); assert.equal(await count(),0);
    assert.match(await client.evaluate('document.querySelector("[data-outcome-confirmation]").innerText'),/Learning Grant/);
    assert.equal(await client.evaluate('document.querySelector("[data-outcome-confirmation]").contains(document.activeElement)'),true);
    assert.equal(await client.evaluate('document.querySelector("form.admin-panel").getBoundingClientRect().x'),before.x);
    await shot('admin-publication-outcome-confirmation');
    console.log('ADMIN_PROMPT_OPEN');
    await click('Go back',confirmScope); await closedPrompt(); assert.equal(await count(),0);
    assert.equal(await client.evaluate('document.querySelector("form.admin-panel textarea").value'),'The publication checks are complete.');
    assert.equal(await client.evaluate('scrollY'),before.y);
    await click('Save decision'); await visiblePrompt(); await click('Record outcome',confirmScope);
    await wait(async()=>await count()===1,'one confirmed publication save'); await closedPrompt();
    assert.equal(await client.evaluate('fixture.writes[0].payload.status'),'published');
    console.log('ADMIN_CANCEL_AND_CONFIRM_PASSED');

    await client.evaluate('fixture.agreementOpen=true;fixture.termsAccepted=true;fixture.note="Keep this agreement note."');
    await wait(()=>client.evaluate('!!document.querySelector("#recipient-agreement-title")'),'agreement modal');
    await click('Accept agreement'); await visiblePrompt(); assert.equal(await count(),1);
    await shot('applicant-agreement-confirmation-desktop');
    assert.ok(await client.evaluate('Number(getComputedStyle(document.querySelector("[data-outcome-confirmation]").parentElement).zIndex)>Number(getComputedStyle(document.querySelector("#recipient-agreement-title").closest("[role=presentation]")).zIndex)'));
    await escape(); await closedPrompt(); assert.equal(await client.evaluate('fixture.agreementOpen'),true);
    assert.equal(await client.evaluate('fixture.note'),'Keep this agreement note.'); assert.equal(await count(),1);
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await click('Accept agreement'); await visiblePrompt();
    const rect = await client.evaluate('(()=>{const d=document.querySelector("[data-outcome-confirmation]"),r=d.getBoundingClientRect();return {fits:r.top>=0&&r.bottom<=innerHeight,overflow:d.scrollWidth>d.clientWidth+1,focus:d.contains(document.activeElement)}})()');
    assert.deepEqual(rect,{fits:true,overflow:false,focus:true}); await shot('applicant-agreement-confirmation-mobile');
    await click('Accept agreement',confirmScope); await wait(async()=>await count()===2,'one confirmed agreement response');
    assert.equal(await client.evaluate('fixture.writes[1].agreement'),'accepted');
    console.log('APPLICANT_NESTED_CANCEL_AND_CONFIRM_PASSED');
    await client.send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});

    await client.evaluate('fixture.reopenOpen=true');
    await wait(()=>client.evaluate('!!document.querySelector("#decision-reopen-title")'),'decision correction modal');
    await input('[aria-labelledby="decision-reopen-title"] textarea','Recheck the corrected examination record.');
    await click('Review correction'); await visiblePrompt(); await escape(); await closedPrompt();
    assert.equal(await client.evaluate('fixture.reopenOpen'),true);
    assert.equal(await client.evaluate('document.querySelector("[aria-labelledby=decision-reopen-title] textarea").value'),'Recheck the corrected examination record.');
    assert.equal(await count(),2);
    await click('Review correction'); await visiblePrompt(); await click('Reopen decision',confirmScope);
    await wait(async()=>await count()===3,'one confirmed reopening');
    assert.equal(await client.evaluate('fixture.reopenOpen'),false);
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true; report.mockedMutations=await count();
    console.log('PASS Real outcome dialogs, Cancel/Confirm, Escape, nested layers, retained drafts, scroll position and applicant mobile layout. In-memory saves only.');
` + source.slice(end);
source = source.replace('finally { await writeFile(', 'finally { await fixtureServer.close(); await writeFile(');
try { await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`); }
catch (error) { console.error(error.message); process.exitCode = 1; }
