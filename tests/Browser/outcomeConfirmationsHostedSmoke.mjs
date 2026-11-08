import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replaceAll('accountability-hosted-part6', 'outcome-confirmations-hosted')
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'outcome-confirmations-hosted.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Hosted browser harness structure changed.');
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
source = source.slice(0, start) + `const expectedRootScript = ${JSON.stringify(manifest['resources/js/app.js'].file)};\n` + String.raw`
    const localPath = href => { const url = new URL(href, origin); assert.equal(url.origin, origin); return url.pathname + url.search; };
    const prompt = () => wait(() => client.evaluate('!!document.querySelector("[data-outcome-confirmation]")'), 'outcome prompt');
    const closedPrompt = () => wait(() => client.evaluate('!document.querySelector("[data-outcome-confirmation]")'), 'closed outcome prompt');
    const escape = async () => { await client.send('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape'}); await client.send('Input.dispatchKeyEvent', {type:'keyUp',key:'Escape',code:'Escape'}); };
    const confirmation = 'document.querySelector("[data-outcome-confirmation]")';
    const response = await fetch(origin + '/login'); assert.equal(response.status,200);
    assert.match(response.headers.get('cache-control'),/no-store/);
    assert.equal(response.headers.get('x-content-type-options'),'nosniff');
    assert.ok(response.headers.get('strict-transport-security'));
    assert.ok((await response.text()).includes(expectedRootScript),'hosting uses the current local build');
    report.latestBuild=true; report.securityHeaders=true;

    await login('platform.reviews');
    const queue = await get('/admin/workspaces/reviews/programs/data?status=all'); assert.equal(queue.status,200);
    const program = queue.body.items.find(p => ['pending_review','published','rejected'].includes(p.status)); assert.ok(program,'reviewable program');
    const programUrl = '/admin/scholarships/' + program.id + '/review/data';
    const beforeProgram = await get(programUrl); assert.equal(beforeProgram.status,200);
    await navigate('/admin/workspaces/reviews/programs/' + program.id,'!!document.querySelector("form.admin-panel textarea") && !!document.querySelector("#outcome-confirmation-host")');
    const draft = 'Publication checks reviewed; this note will not be saved.';
    await client.evaluate('(()=>{const f=document.querySelector("form.admin-panel");const select=f.querySelector("select");select.value="published";select.dispatchEvent(new Event("change",{bubbles:true}));const t=f.querySelector("textarea");t.value=' + JSON.stringify(draft) + ';t.dispatchEvent(new Event("input",{bubbles:true}));f.scrollIntoView({block:"center"})})()');
    await click('Save decision'); await prompt();
    assert.match(await client.evaluate('document.querySelector("[data-outcome-confirmation]").innerText'),/Published/);
    assert.equal(await client.evaluate('document.querySelector("[data-outcome-confirmation]").contains(document.activeElement)'),true);
    await shot('admin-publication-confirmation');
    await click('Go back',confirmation); await closedPrompt();
    assert.equal(await client.evaluate('document.querySelector("form.admin-panel textarea").value'),draft);
    await click('Save decision'); await prompt(); await escape(); await closedPrompt();
    assert.deepEqual((await get(programUrl)).body.scholarship,beforeProgram.body.scholarship);
    report.adminCancelPreservesDecision=true;

    let testedSession = false;
    for (const account of ['marilag.selection','silayan.selection']) {
        await login(account);
        const directory = await get('/provider/workspaces/selection/sessions/data?queue=active'); assert.equal(directory.status,200);
        for (const session of directory.body.sessions) {
            if (!['scheduled','ongoing'].includes(session.status) || !session.capacity.assigned) continue;
            const dataUrl = '/provider/scholarships/' + session.program.id + '/stage-sessions/' + session.id;
            const before = await get(dataUrl); assert.equal(before.status,200);
            const assignment = before.body.session.assignments.find(a=>['assigned','acknowledged'].includes(a.status));
            if (!assignment) continue;
            await navigate('/provider/workspaces/selection/sessions/' + session.id,'!!document.querySelector("nav[aria-label^=Session]")');
            await client.evaluate('(()=>{const b=[...document.querySelectorAll("nav[aria-label^=Session] button")].find(b=>b.innerText.includes("Roster"));b.click()})()');
            await wait(()=>client.evaluate('!!document.querySelector("tbody select")'),'attendance dropdown');
            const previous = await client.evaluate('document.querySelector("tbody select").value');
            await client.evaluate('(()=>{const s=document.querySelector("tbody select");s.value=s.value==="present"?"absent":"present";s.dispatchEvent(new Event("change",{bubbles:true}))})()');
            await prompt(); await shot('provider-attendance-confirmation');
            await click('Go back',confirmation); await closedPrompt();
            assert.equal(await client.evaluate('document.querySelector("tbody select").value'),previous);
            assert.deepEqual((await get(dataUrl)).body.session,before.body.session);
            report.providerCancelPreservesAttendance=true; testedSession=true; break;
        }
        if (testedSession) break;
    }
    assert.equal(testedSession,true,'scheduled recipient session tested');

    await login('learner.release.confirm');
    const applications = await get('/dashboard/applications/data'); assert.equal(applications.status,200);
    const award = applications.body.applications.find(a=>a.recipient_agreement?.status==='accepted'); assert.ok(award);
    const benefitsUrl = '/dashboard/monitoring/' + award.id + '/data?view=benefits';
    const benefits = await get(benefitsUrl); assert.equal(benefits.status,200);
    const release = benefits.body.detail.content.directory.action_required[0]; assert.ok(release,'benefit awaiting recipient confirmation');
    await navigate(localPath(release.href),'document.body.innerText.includes("Confirm or report")');
    await click('Confirm or report');
    await wait(()=>client.evaluate('!!document.querySelector("#benefit-response-title")'),'receipt response form');
    await client.evaluate('document.querySelector("[aria-labelledby=benefit-response-title] input[type=checkbox]").click()');
    await click('Submit response'); await prompt();
    assert.match(await client.evaluate('document.querySelector("[data-outcome-confirmation]").innerText'),/Received As Listed/);
    await shot('applicant-benefit-response-confirmation');
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    assert.deepEqual(await client.evaluate('(()=>{const d=document.querySelector("[data-outcome-confirmation]"),r=d.getBoundingClientRect();return {fits:r.top>=0&&r.bottom<=innerHeight+1,overflow:d.scrollWidth>d.clientWidth+2,focused:d.contains(document.activeElement)}})()'),{fits:true,overflow:false,focused:true});
    await shot('applicant-benefit-response-confirmation-mobile');
    await escape(); await closedPrompt();
    assert.equal(await client.evaluate('!!document.querySelector("#benefit-response-title")'),true);
    assert.equal(await client.evaluate('document.querySelector("[aria-labelledby=benefit-response-title] input[type=checkbox]").checked'),true);
    await click('Cancel','document.querySelector("[aria-labelledby=benefit-response-title]")');
    assert.deepEqual((await get(benefitsUrl)).body.detail.content,benefits.body.detail.content);
    report.applicantCancelPreservesReceipt=true; report.applicantMobile=true;
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Live admin, provider and applicant confirmations. Cancel/Escape keep records unchanged; mobile popup fits. No decision or receipt submitted.');
` + source.slice(end);
try { await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`); }
catch (error) { console.error(error.message); process.exitCode=1; }
