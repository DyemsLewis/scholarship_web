import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("accountability-hosted-part6';", "branding-consistency-hosted';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'branding-consistency-hosted.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    const absolute = value => new URL(value, origin).href;
    async function logo(selector, expected) {
        await wait(() => client.evaluate('(()=>{const i=document.querySelector(' + JSON.stringify(selector) + ');return i && i.complete && i.naturalWidth>0})()'), 'loaded logo ' + selector);
        assert.equal(await client.evaluate('document.querySelector(' + JSON.stringify(selector) + ').currentSrc'), absolute(expected));
        assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    }
    await login('learner.apply');
    const catalog = await get('/dashboard/scholarships/data'); assert.equal(catalog.status, 200);
    const providerId = catalog.body.scholarships[0].provider.id;
    const provider = await get('/dashboard/providers/' + providerId + '/data'); assert.equal(provider.status, 200);
    assert.ok(provider.body.provider.programs.length > 0);
    await navigate('/dashboard/providers/' + providerId, '!!document.querySelector("[data-provider-program] [data-program-logo]")');
    for (let i=0; i<provider.body.provider.programs.length; i++) {
        const program = provider.body.provider.programs[i];
        assert.notEqual(program.logo_url, program.cover_image_url);
        const prefix = '[data-provider-program]:nth-child(' + (i+1) + ') ';
        await logo(prefix + '[data-program-logo]', program.logo_url);
        await logo(prefix + '[data-program-cover]', program.cover_image_url);
    }
    await client.evaluate('document.querySelector("[data-provider-program]").scrollIntoView({block:"center"})');
    await shot('public-provider-program-logo-and-cover');
    await client.send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
    await logo('[data-program-logo]', provider.body.provider.programs[0].logo_url);
    await shot('public-provider-program-mobile');
    await client.send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    report.publicProviderPrograms = provider.body.provider.programs.length;

    await login('learner.monitoring.upload');
    const overview = await get('/dashboard/overview-data'); assert.equal(overview.status, 200);
    const application = overview.body.dashboard.applications[0]; assert.ok(application);
    report.monitoringViews = [];
    for (const [view, suffix] of Object.entries({overview:'', 'check-ins':'/check-ins', benefits:'/benefits', history:'/history'})) {
        const base = '/dashboard/monitoring/' + application.id;
        const data = await get(base + '/data?view=' + view); assert.equal(data.status, 200);
        const identity = data.body.detail.identity.program;
        assert.equal(identity.image_url, identity.logo_url);
        assert.notEqual(identity.image_url, identity.cover_image_url);
        await navigate(base + suffix, '!!document.querySelector("[data-monitoring-detail-header] figcaption img")');
        await logo('[data-monitoring-detail-header] figcaption img', identity.logo_url);
        if (view === 'overview') await shot('monitoring-header-logo');
        report.monitoringViews.push(view);
    }

    report.providerPrograms = [];
    for (const account of ['marilag.programs', 'silayan.programs']) {
        await login(account);
        const directory = await get('/provider/programs/data'); assert.equal(directory.status, 200);
        assert.ok(directory.body.programs.length > 0);
        for (const item of directory.body.programs) {
            const record = await get('/provider/scholarships/' + item.id); assert.equal(record.status, 200);
            const program = record.body.scholarship;
            assert.equal(program.image_url, program.logo_url);
            assert.notEqual(program.image_url, program.cover_image_url);
            const preview = await get('/provider/previews/programs/' + item.id + '/data'); assert.equal(preview.status, 200);
            assert.equal(preview.body.detail.identity.logo_url, program.logo_url);
            assert.equal(preview.body.detail.identity.cover_image_url, program.cover_image_url);
            report.providerPrograms.push({account, title:program.title});
        }
        const id = directory.body.programs[0].id;
        const views = account === 'marilag.programs' ? ['summary','eligibility','award','application','provider'] : ['summary'];
        for (const view of views) {
            const data = await get('/provider/previews/programs/' + id + '/data?section=' + view);
            assert.equal(data.status, 200);
            await navigate('/provider/previews/programs/' + id + (view === 'summary' ? '' : '/' + view), '!!document.querySelector("[data-scholarship-logo]")');
            await logo('[data-scholarship-logo]', data.body.detail.identity.logo_url);
            await logo('[data-scholarship-detail-header] figure>img', data.body.detail.identity.cover_image_url);
            if (view === 'summary') await shot(account + '-scholarship-preview');
        }
    }

    await login('marilag.profile');
    const organization = await get('/provider/previews/organization/data'); assert.equal(organization.status, 200);
    await navigate('/provider/previews/organization', '!!document.querySelector("[data-program-logo]")');
    await logo('[data-program-logo]', organization.body.provider.programs[0].logo_url);
    await logo('[data-program-cover]', organization.body.provider.programs[0].cover_image_url);
    report.organizationPreview = true;

    await login('platform.reviews');
    const programs = await get('/admin/workspaces/reviews/programs/data?status=all'); assert.equal(programs.status, 200);
    for (const item of programs.body.items) {
        assert.equal(item.image_url, item.logo_url);
        assert.notEqual(item.image_url, item.cover_image_url);
        const record = await get('/admin/scholarships/' + item.id + '/review/data'); assert.equal(record.status, 200);
        assert.equal(record.body.scholarship.image_url, item.logo_url);
        assert.equal(record.body.scholarship.cover_image_url, item.cover_image_url);
    }
    const sessions = await get('/admin/workspaces/reviews/selection-activities/data?status=all'); assert.equal(sessions.status, 200);
    for (const item of sessions.body.items) if (item.logo_url) assert.equal(item.image_url, item.logo_url);
    await navigate('/admin/workspaces/reviews/programs', '!!document.querySelector("tbody img")');
    await wait(() => client.evaluate('document.querySelectorAll("tbody img").length>0 && [...document.querySelectorAll("tbody img")].every(i=>i.complete && i.naturalWidth>0)'), 'loaded admin program logos');
    const adminImages = await client.evaluate('[...document.querySelectorAll("tbody img")].map(i=>({src:i.currentSrc,fit:getComputedStyle(i).objectFit,alt:i.alt}))');
    report.adminImages = adminImages;
    for (const item of adminImages) {
        assert.ok(programs.body.items.some(p=>absolute(p.logo_url)===item.src));
        assert.equal(item.fit, 'contain');
        assert.notEqual(item.alt, 'Provider logo');
    }
    await shot('admin-program-review-logos');
    report.adminPrograms = programs.body.items.length;
    report.adminSessions = sessions.body.items.length;

    report.adminDashboardImages = [];
    for (const role of ['billing', 'finance', 'accounts', 'support']) {
        await login('platform.' + role);
        const base = '/admin/workspaces/' + role;
        const data = await get(base + '/dashboard/data'); assert.equal(data.status, 200);
        const images = data.body.sections.flatMap(s=>s.items).filter(i=>i.image_url);
        for (const item of images) assert.ok(['cover','contain'].includes(item.image_fit));
        if (role === 'finance' || role === 'billing') for (const item of images) assert.equal(item.image_fit, 'contain');
        await navigate(base, 'document.readyState==="complete" && !!document.querySelector("h1")');
        if (images.length) {
            await wait(() => client.evaluate('document.querySelectorAll(".workspace-dashboard-item img").length===' + images.length), 'dashboard image rows');
            await wait(() => client.evaluate('[...document.querySelectorAll(".workspace-dashboard-item img")].every(i=>i.complete && i.naturalWidth>0)'), 'loaded dashboard images');
            const rendered = await client.evaluate('[...document.querySelectorAll(".workspace-dashboard-item img")].map(i=>({src:i.currentSrc,fit:getComputedStyle(i).objectFit}))');
            for (const image of rendered) assert.ok(images.some(i=>absolute(i.image_url)===image.src && i.image_fit===image.fit));
        }
        report.adminDashboardImages.push({role,count:images.length});
    }
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Public provider cards, monitoring sections, two provider organizations, program/organization previews, admin review queues, and four admin dashboards keep logos and covers separate. No business actions submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
