import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "application-scholarship-logos-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'application-scholarship-logos-hosted.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    report.accounts = [];
    const sections = {summary:'', submission:'/submission', activities:'/activities', outcome:'/outcome', history:'/history'};
    for (const name of ['learner.screening', 'learner.interview.confirm']) {
        await login(name);
        const list = await get('/dashboard/applications/data'); assert.equal(list.status, 200);
        assert.ok(list.body.applications.length > 0);
        for (const row of list.body.applications) {
            assert.equal(row.scholarship.image_url, row.scholarship.logo_url);
            assert.notEqual(row.scholarship.image_url, row.scholarship.cover_image_url);
        }
        await navigate('/dashboard/applications', '!!document.querySelector("article img")');
        await wait(() => client.evaluate('[...document.querySelectorAll("article img")].every(i=>i.complete && i.naturalWidth>0)'), 'active application logos');
        const images = await client.evaluate('[...document.querySelectorAll("article img")].map(i=>({title:i.alt,url:i.currentSrc}))');
        for (const image of images) {
            const row = list.body.applications.find(a=>a.scholarship.title === image.title); assert.ok(row);
            assert.equal(image.url, row.scholarship.logo_url);
        }
        assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
        await shot(name + '-active-applications');
        const application = list.body.applications[0];
        report.verified_application_ids.push(application.id);
        const dataUrl = '/dashboard/applications/' + application.id + '/data';
        const initial = await get(dataUrl); assert.equal(initial.status, 200);
        for (const [view, suffix] of Object.entries(sections)) {
            const data = await get(dataUrl + '?view=' + view); assert.equal(data.status, 200);
            const program = data.body.detail.identity.program;
            assert.equal(program.image_url, application.scholarship.logo_url);
            assert.equal(program.logo_url, program.image_url);
            assert.equal(program.cover_image_url, application.scholarship.cover_image_url);
            await navigate('/dashboard/applications/' + application.id + suffix, '!!document.querySelector("[data-application-detail-header] figcaption img")');
            await wait(() => client.evaluate('(()=>{const i=document.querySelector("[data-application-detail-header] figcaption img");return i.complete && i.naturalWidth>0})()'), 'application header logo');
            const header = await client.evaluate('(()=>{const h=document.querySelector("[data-application-detail-header]");return {logo:h.querySelector("figcaption img").currentSrc,visual:h.querySelector("figure>img").currentSrc,overflow:document.documentElement.scrollWidth>innerWidth+2}})()');
            assert.equal(header.logo, program.logo_url);
            assert.notEqual(header.visual, program.logo_url);
            assert.equal(header.overflow, false);
            assert.equal(data.body.application.status, initial.body.application.status);
            if (name === 'learner.screening' && view === 'summary') {
                await shot('application-overview-logo-desktop');
                await client.send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
                assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
                await shot('application-overview-logo-mobile');
                await client.send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            }
        }
        report.accounts.push({name, applicationLogos:images.length, verifiedDetailViews:Object.keys(sections)});
    }
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted active application cards and all five application detail sections use scholarship logos; background visuals remain separate; desktop/mobile fit; no business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
