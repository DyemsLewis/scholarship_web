import { readFile } from 'node:fs/promises';

const origin = process.env.DETAIL_OVERVIEWS_ORIGIN || 'http://127.0.0.1:8000';
const hosted = origin.startsWith('https:');
const folder = process.env.DETAIL_OVERVIEWS_OUTPUT || 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/detail-overviews-' + (hosted ? 'hosted' : 'local') + '-20261115';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + `
    const accounts = ${JSON.stringify(hosted ? { fresh: 'learner.profile', active: 'learner.aurora', selected: 'learner.aurora', provider: 'orivanta.programs' } : { fresh: 'cohort.learner.apply', active: 'cohort.learner.screening', selected: 'cohort.learner.monitoring.appointment', provider: 'nuvareya.programs' })};
` + String.raw`
    const fits = scope => client.evaluate("(()=>{const r=document.querySelector("+JSON.stringify(scope)+");return {page:document.documentElement.scrollWidth<=innerWidth+2,content:r.scrollWidth<=r.clientWidth+2,text:[...r.querySelectorAll('h2,h3,dd,p')].every(e=>e.scrollWidth<=e.clientWidth+2)}})()");
    async function capture(scope, name) {
        const viewport = await client.evaluate('({width:innerWidth,height:innerHeight})');
        await client.send('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: 4000, deviceScaleFactor: 1, mobile: viewport.width < 640 });
        await client.evaluate('document.fonts.ready');
        await client.evaluate("document.querySelectorAll("+JSON.stringify(scope+" img")+").forEach(i=>i.loading='eager')");
        await wait(() => client.evaluate("[...document.querySelectorAll("+JSON.stringify(scope+" img")+")].every(i=>i.complete&&i.naturalWidth>0)"), 'overview images');
        await client.evaluate("window.scrollTo({top:0,behavior:'instant'})");
        await delay(250);
        const clip = await client.evaluate("(()=>{const r=document.querySelector("+JSON.stringify(scope)+").getBoundingClientRect();return {x:r.left,y:r.top+scrollY,width:r.width,height:r.height,scale:1}})()");
        const { data } = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip });
        const file = path.join(folder, name + '.png'); await writeFile(file, Buffer.from(data, 'base64')); report.screenshots.push(file);
        await client.send('Emulation.setDeviceMetricsOverride', { width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: viewport.width < 640 });
    }
    await login(accounts.fresh);
    const scholarships = await get('/dashboard/scholarships/data'); assert.equal(scholarships.status, 200);
    const program = scholarships.body.scholarships.filter(p=>!p.has_applied).sort((a,b)=>b.title.length-a.title.length)[0]; assert.ok(program);
    const scholarshipUrl = '/dashboard/scholarships/' + program.id;
    const scholarship = (await get(scholarshipUrl+'/data')).body.detail;
    await navigate(scholarshipUrl, "!!document.querySelector('[data-scholarship-overview]')");
    assert.equal(await client.evaluate("document.querySelectorAll('[data-scholarship-overview] img').length"), 0);
    assert.equal(await client.evaluate("!!document.querySelector('[data-scholarship-detail-header] [data-scholarship-cover] img')"), true);
    assert.equal(await client.evaluate("document.querySelector('[data-program-description]').textContent.trim()"), scholarship.content.description);
    assert.equal(await client.evaluate("!!document.querySelector('[data-scholarship-detail-header] [data-scholarship-next-action]')"), false);
    assert.equal(await client.evaluate("document.querySelectorAll('[data-overview-resource]').length"), 3);
    if (scholarship.content.primary_benefit) {
        assert.ok((await client.evaluate("document.querySelector('[data-overview-benefit]').innerText")).includes(scholarship.content.primary_benefit.title));
        assert.equal(await client.evaluate("!!document.querySelector('[data-overview-benefit-facts] dd')"), true);
    }
    for (const width of [1440, 1024, 768, 390, 320]) {
        await client.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 640 });
        assert.deepEqual(await fits('[data-scholarship-overview]'), { page: true, content: true, text: true });
        if (width===1440 || width===390) await capture('[data-scholarship-overview]', 'scholarship-overview-'+width);
    }
    const instructions = await client.evaluate("document.querySelector('[data-overview-resource=files]').getAttribute('href')");
    assert.equal(instructions, scholarship.navigation.find(n=>n.key==='application').href);
    await navigate(instructions, "!!document.querySelector('[data-scholarship-how-to-apply]')");
    await login(accounts.active);
    const applications = await get('/dashboard/applications/data'); assert.equal(applications.status, 200);
    const active = applications.body.applications.find(a=>!a.recipient_monitoring?.eligible); assert.ok(active);
    const url = '/dashboard/applications/' + active.id;
    const application = (await get(url+'/data')).body.detail;
    await navigate(url, "!!document.querySelector('[data-application-overview]')");
    assert.equal(await client.evaluate("document.querySelectorAll('[data-application-update-image]').length"), 1);
    const overviewText = await client.evaluate("document.querySelector('[data-application-overview]').innerText");
    for (const step of application.progress.steps) assert.ok(overviewText.includes(step.label));
    for (const text of [application.content.latest_update.title, application.content.latest_update.description, application.content.latest_update.occurred_at]) if(text) assert.ok(overviewText.includes(text));
    assert.equal(await client.evaluate("Number(document.querySelector('[role=progressbar]').getAttribute('aria-valuenow'))"), application.progress.percent);
    assert.equal(await client.evaluate("!!document.querySelector('[data-application-upcoming]')"), !!application.content.upcoming_activity && application.decision.state!=='action_required');
    assert.equal(await client.evaluate("document.querySelector('[data-application-record-details]').open"), false);
    await client.evaluate("document.querySelector('[data-application-record-details] summary').click()");
    const information = await client.evaluate("document.querySelector('[data-application-record-details]').innerText");
    for (const row of application.content.record) assert.ok(information.includes(row.value));
    await client.evaluate("document.querySelector('[data-application-record-details] summary').click()");
    assert.equal(await client.evaluate("document.querySelector('[data-application-related] a').getAttribute('href')"), application.content.links.scholarship);
    for (const width of [1440, 1024, 768, 390, 320]) {
        await client.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 640 });
        assert.deepEqual(await fits('[data-application-overview]'), { page: true, content: true, text: true });
        const stacked = await client.evaluate("(()=>{const groups=[document.querySelector('[data-application-overview-stack]'),document.querySelector('[data-application-related]')];return groups.every(g=>[...g.children].every((e,i,items)=>{const r=e.getBoundingClientRect(),p=g.getBoundingClientRect();return Math.abs(r.left-p.left)<=2&&Math.abs(r.width-p.width)<=2&&(!i||r.top>=items[i-1].getBoundingClientRect().bottom-1)}))})()");
        assert.equal(stacked, true, 'Application overview must remain single-column at width '+width);
        assert.equal(await client.evaluate("(()=>{const i=document.querySelector('[data-application-update-image]').getBoundingClientRect(),t=document.querySelector('#application-update-title').parentElement.getBoundingClientRect();return innerWidth>=640?i.left>=t.right-1:i.top>=t.bottom-1})()"), true, 'Image must be right-aligned on desktop and below the text on narrow screens');
        if (width===1440 || width===390) await capture('[data-application-overview]', 'application-overview-'+width);
    }
    if (accounts.selected !== accounts.active) await login(accounts.selected);
    const accepted = (await get('/dashboard/applications/data')).body.applications.find(a=>a.recipient_agreement?.status==='accepted'); assert.ok(accepted);
    const selected = (await get('/dashboard/applications/'+accepted.id+'/data')).body.detail;
    await navigate('/dashboard/applications/'+accepted.id, "!!document.querySelector('[data-application-overview]')");
    assert.equal(await client.evaluate("!!document.querySelector('[data-monitoring-detail-header]')"), false);
    assert.equal(await client.evaluate("Number(document.querySelector('[role=progressbar]').getAttribute('aria-valuenow'))"), selected.progress.percent);
    assert.deepEqual(await fits('[data-application-overview]'), { page: true, content: true, text: true });
    await login(accounts.provider);
    const programs = (await get('/provider/programs/data?filter=all')).body.programs;
    const authorized = programs.find(p=>p.id); assert.ok(authorized);
    await navigate('/provider/programs/'+authorized.id, "document.body.innerText.includes('Applicant preview')");
    await click('Applicant preview');
    await wait(() => client.evaluate("!!document.querySelector('[data-applicant-page-preview-modal] [data-scholarship-overview]')"), 'embedded scholarship overview');
    assert.equal(await client.evaluate("document.querySelectorAll('[data-scholarship-overview] .student-badge').length"), 0);
    const pathBefore = await client.evaluate('location.pathname');
    await client.evaluate("document.querySelector('[data-scholarship-overview] [data-overview-resource=eligibility]').click()");
    await wait(() => client.evaluate("!!document.querySelector('[data-applicant-page-preview-modal] #scholarship-section [aria-current=page]') && document.querySelector('#scholarship-section [aria-current=page]').innerText==='Eligibility'"), 'embedded eligibility navigation');
    assert.equal(await client.evaluate('location.pathname'), pathBefore);
    assert.equal(await client.evaluate("!!document.querySelector('[data-applicant-page-preview-modal]')"), true);
    assert.equal(await client.evaluate("!!document.querySelector('[data-scholarship-primary-action]')"), false);
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Redesigned scholarship/application overviews, labeled benefits, progress and submission facts, native guide links, accepted award record, five widths and embedded read-only provider preview. No business writes submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
