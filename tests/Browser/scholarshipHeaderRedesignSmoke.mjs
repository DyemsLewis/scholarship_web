import { readFile } from 'node:fs/promises';

const origin = process.env.SCHOLARSHIP_HEADER_ORIGIN || 'http://127.0.0.1:8000';
const hosted = origin.startsWith('https:');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/scholarship-header-' + (hosted ? 'hosted' : 'local') + '-20261115';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + `
    const applicant = ${JSON.stringify(hosted ? 'learner.cielo' : 'cohort.learner.apply')};
    const provider = ${JSON.stringify(hosted ? 'orivanta.programs' : 'nuvareya.programs')};
` + String.raw`
    const selector = '[data-scholarship-detail-header]';
    const fits = () => client.evaluate("(()=>{const h=document.querySelector('[data-scholarship-detail-header]');return {page:document.documentElement.scrollWidth<=innerWidth+2,header:h.scrollWidth<=h.clientWidth+2,title:h.querySelector('h1').scrollWidth<=h.querySelector('h1').clientWidth+2}})()");
    async function ready() {
        await client.evaluate('document.fonts.ready');
        await wait(() => client.evaluate("[...document.querySelectorAll('[data-scholarship-detail-header] img')].every(i=>i.complete&&i.naturalWidth>0)"), 'loaded header images');
        await client.evaluate('window.scrollTo(0,0)');
    }
    async function inspect(detail, overview = true) {
        await ready();
        const actual = await client.evaluate("(()=>{const h=document.querySelector('[data-scholarship-detail-header]');return {title:h.querySelector('h1').innerText,provider:h.innerText,logo:h.querySelector('[data-scholarship-logo]').getAttribute('src'),cover:h.querySelector('[data-scholarship-cover] img')?.getAttribute('src'),facts:h.querySelector('[data-scholarship-key-facts]')?.innerText,actionInside:!!h.querySelector('[data-scholarship-next-action]')}})()");
        assert.equal(actual.title, detail.identity.title);
        assert.ok(actual.provider.includes(detail.identity.provider.name));
        assert.equal(actual.logo, detail.identity.logo_url);
        assert.notEqual(actual.logo, detail.identity.cover_image_url);
        assert.equal(actual.actionInside, false);
        if (overview) {
            assert.equal(actual.cover, detail.identity.cover_image_url);
            for (const fact of detail.snapshot) {
                assert.ok(actual.facts.toLowerCase().includes(fact.label.toLowerCase()));
                assert.ok(actual.facts.includes(fact.value));
                if (fact.note) assert.ok(actual.facts.includes(fact.note));
            }
        } else assert.equal(actual.facts, undefined);
        assert.deepEqual(await fits(), { page: true, header: true, title: true });
    }
    await login(applicant);
    const list = await get('/dashboard/scholarships/data'); assert.equal(list.status, 200);
    const program = list.body.scholarships.filter(p => p.logo_url && p.cover_image_url && p.logo_url !== p.cover_image_url).sort((a, b) => b.title.length - a.title.length)[0]; assert.ok(program);
    const response = await get('/dashboard/scholarships/' + program.id + '/data'); assert.equal(response.status, 200);
    const detail = response.body.detail;
    await navigate('/dashboard/scholarships/' + program.id, "!!document.querySelector('[data-scholarship-key-facts]')");
    await inspect(detail);
    await shot('scholarship-header-desktop');
    const rect = await client.evaluate("(()=>{const r=document.querySelector('[data-scholarship-detail-header]').getBoundingClientRect();return {x:r.x,y:r.y+scrollY,width:r.width,height:r.height,scale:1}})()");
    const cropped = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: rect });
    const croppedPath = path.join(folder, 'scholarship-header.png'); await writeFile(croppedPath, Buffer.from(cropped.data, 'base64')); report.screenshots.push(croppedPath);
    for (const width of [1024, 768, 390, 320]) {
        await client.send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: width < 640 });
        await inspect(detail);
        if (width === 390) await shot('scholarship-header-mobile');
    }
    const eligibility = detail.navigation.find(n => n.key === 'eligibility');
    await navigate(eligibility.href, "!!document.querySelector('[data-scholarship-detail-header]') && !document.querySelector('[data-scholarship-key-facts]')");
    await inspect(detail, false);
    assert.equal(await client.evaluate("!!document.querySelector('[data-scholarship-cover]')"), false);
    await login(provider);
    const programs = await get('/provider/programs/data?filter=all'); assert.equal(programs.status, 200);
    const rows = programs.body.programs?.data || programs.body.programs || programs.body.scholarships || [];
    const authorized = rows.find(p => p.id); assert.ok(authorized, JSON.stringify(Object.keys(programs.body)));
    await navigate('/provider/previews/programs/' + authorized.id, "!!document.querySelector('[data-scholarship-key-facts]')");
    await ready();
    const previewPath = await client.evaluate('location.pathname');
    assert.equal(await client.evaluate("document.querySelector('[data-scholarship-detail-header] a').getAttribute('aria-disabled')"), 'true');
    await client.evaluate("document.querySelector('[data-scholarship-detail-header] a').click()");
    assert.equal(await client.evaluate('location.pathname'), previewPath);
    assert.equal(await client.evaluate("!!document.querySelector('[data-scholarship-primary-action]')"), false);
    assert.deepEqual(await fits(), { page: true, header: true, title: true });
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Scholarship header branding, all published facts, desktop/mobile layouts, compact section header and read-only provider preview. No business records changed.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
