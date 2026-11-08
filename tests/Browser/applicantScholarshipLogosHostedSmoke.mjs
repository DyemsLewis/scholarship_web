import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "applicant-scholarship-logos-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'applicant-scholarship-logos-hosted.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    await login('learner.apply');
    const data = await get('/dashboard/scholarships/data'); assert.equal(data.status, 200);
    const programs = data.body.scholarships; assert.ok(programs.length > 0);
    assert.ok(programs.some(p => p.logo_url !== p.cover_image_url), 'Independent logo and cover assets must exist');
    const expected = Object.fromEntries(programs.map(p => [p.title, p.logo_url]));
    async function checkLogos(scope = 'document') {
        await wait(() => client.evaluate('[' + '...' + scope + '.querySelectorAll("[data-scholarship-logo]")].every(i=>i.complete && i.naturalWidth>0)'), 'loaded scholarship logos');
        const images = await client.evaluate('[' + '...' + scope + '.querySelectorAll("[data-scholarship-logo]")].map(i=>({title:i.alt,url:i.currentSrc}))');
        for (const image of images) assert.equal(image.url, expected[image.title], 'Incorrect logo for ' + image.title);
        assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
        return images.length;
    }
    await navigate('/dashboard/scholarships', '!!document.querySelector("[data-scholarship-logo]")');
    report.findLogos = await checkLogos(); assert.ok(report.findLogos > 0);
    await shot('01-find-scholarships-desktop');
    await client.send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
    await checkLogos(); await shot('02-find-scholarships-mobile');
    await client.send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await click('Compare scholarships');
    await wait(() => client.evaluate('!!document.querySelector("[role=dialog]")'), 'comparison dialog');
    report.compareLogos = await checkLogos('document.querySelector("[role=dialog]")');
    await shot('03-comparison-logos');
    await client.evaluate("[...document.querySelectorAll('button')].find(b=>b.getAttribute('aria-label')==='Close scholarship comparison').click()");
    await navigate('/dashboard/scholarships?view=saved', 'document.body.innerText.includes("Review programs saved for later") && !document.body.innerText.includes("Loading scholarships...")');
    report.savedLogos = await checkLogos();
    const after = await get('/dashboard/scholarships/data'); assert.equal(after.status, 200);
    assert.deepEqual(after.body.scholarships.map(p=>({id:p.id,saved:p.is_saved,applied:p.has_applied,logo:p.logo_url,cover:p.cover_image_url})), programs.map(p=>({id:p.id,saved:p.is_saved,applied:p.has_applied,logo:p.logo_url,cover:p.cover_image_url})));
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted finder, saved and comparison views use program logos; mobile fits; no business data changed.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
