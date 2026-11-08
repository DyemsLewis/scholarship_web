import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "dashboard-scholarship-logos-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'dashboard-scholarship-logos-hosted.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    report.accounts = [];
    for (const name of ['learner.screening', 'learner.apply']) {
        await login(name);
        const overview = await get('/dashboard/overview-data'); assert.equal(overview.status, 200);
        const finder = await get('/dashboard/scholarships/data'); assert.equal(finder.status, 200);
        const programs = finder.body.scholarships;
        const dashboard = overview.body.dashboard;
        const cards = [...dashboard.applications, dashboard.recommendation].filter(Boolean);
        assert.ok(dashboard.recommendation, 'A recommendation is needed to inspect its logo');
        if (name === 'learner.screening') assert.ok(dashboard.applications.length > 0);
        for (const card of cards) {
            const program = programs.find(p => p.title === card.title); assert.ok(program);
            assert.equal(card.image_url, program.logo_url, 'Logo mismatch: ' + card.title);
            assert.notEqual(card.image_url, program.cover_image_url, 'Background used for logo: ' + card.title);
        }
        await navigate('/dashboard', '!!document.querySelector("[data-dashboard-recommendation]")');
        const selector = '[data-dashboard-application] img, [data-dashboard-recommendation] img';
        await wait(() => client.evaluate('Array.from(document.querySelectorAll(' + JSON.stringify(selector) + ')).every(i=>i.complete && i.naturalWidth>0)'), 'loaded dashboard logos');
        const images = await client.evaluate('Array.from(document.querySelectorAll(' + JSON.stringify(selector) + ')).map(i=>({alt:i.alt,url:i.currentSrc}))');
        assert.equal(images.length, cards.length);
        for (const card of cards) assert.ok(images.some(i => i.alt === card.title + ' program logo' && i.url === card.image_url));
        await client.evaluate('(document.querySelector("[data-dashboard-application]") || document.querySelector("[data-dashboard-recommendation]")).scrollIntoView({block:"center"})');
        assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
        await shot(name + '-dashboard-desktop');
        if (name === 'learner.screening') {
            await client.send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
            assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
            await client.evaluate('document.querySelector("[data-dashboard-application]").scrollIntoView({block:"start"})');
            await shot(name + '-dashboard-mobile');
            await client.send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        }
        const after = await get('/dashboard/overview-data'); assert.equal(after.status, 200);
        assert.deepEqual(after.body.dashboard.applications, dashboard.applications);
        assert.deepEqual(after.body.dashboard.recommendation, dashboard.recommendation);
        report.accounts.push({name, applicationLogos:dashboard.applications.length, recommendationLogo:!!dashboard.recommendation});
    }
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted dashboard application progress and suggestions use loaded program logos, not backgrounds; desktop/mobile fit; no business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
