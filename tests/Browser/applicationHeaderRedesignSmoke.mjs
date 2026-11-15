import { readFile } from 'node:fs/promises';

const origin = process.env.APPLICATION_HEADER_ORIGIN || 'http://127.0.0.1:8000';
const hosted = origin.startsWith('https:');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/application-header-' + (hosted ? 'hosted' : 'local') + '-20261115';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + `
    const accounts = ${JSON.stringify(hosted ? ['learner.aurora', 'learner.aurora'] : ['cohort.learner.screening', 'cohort.learner.monitoring.appointment'])};
` + String.raw`
    const fits = () => client.evaluate("(()=>{const h=document.querySelector('[data-application-detail-header]');return {page:document.documentElement.scrollWidth<=innerWidth+2,header:h.scrollWidth<=h.clientWidth+2,title:h.querySelector('h1').scrollWidth<=h.querySelector('h1').clientWidth+2}})()");
    async function ready() {
        await client.evaluate('document.fonts.ready');
        await wait(() => client.evaluate("[...document.querySelectorAll('[data-application-detail-header] img')].every(i=>i.complete&&i.naturalWidth>0)"), 'application header images');
        await client.evaluate('window.scrollTo(0,0)');
    }
    async function inspect(detail) {
        await ready();
        const actual = await client.evaluate("(()=>{const h=document.querySelector('[data-application-detail-header]');return {title:h.querySelector('h1').innerText,text:h.innerText,logo:h.querySelector('[data-application-program-logo]').getAttribute('src'),cover:h.querySelector('[data-application-cover] img').getAttribute('src'),status:h.querySelector('[data-application-status]').innerText,facts:[...h.querySelectorAll('[data-record-snapshot] dd')].map(e=>e.innerText),provider:h.querySelector('a')?.getAttribute('href'),actions:h.querySelectorAll('button').length}})()");
        assert.equal(actual.title, detail.identity.program.title);
        assert.ok(actual.text.includes(detail.identity.provider.name));
        assert.ok(actual.text.includes(detail.identity.submitted_at));
        assert.equal(actual.status, detail.identity.status.label);
        assert.equal(actual.logo, detail.identity.program.logo_url);
        assert.equal(actual.provider, detail.identity.provider.href);
        assert.notEqual(actual.cover, actual.logo);
        if (detail.identity.program.cover_image_url) assert.equal(actual.cover, detail.identity.program.cover_image_url);
        assert.deepEqual(actual.facts, detail.snapshot.map(f => f.value));
        assert.equal(actual.actions, 0);
        assert.deepEqual(await fits(), { page: true, header: true, title: true });
    }
    async function captureHeader(name) {
        await ready();
        const clip = await client.evaluate("(()=>{const r=document.querySelector('[data-application-detail-header]').getBoundingClientRect();return {x:r.left,y:r.top+scrollY,width:r.width,height:r.height,scale:1}})()");
        const { data } = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip });
        const file = path.join(folder, name + '.png'); await writeFile(file, Buffer.from(data, 'base64')); report.screenshots.push(file);
    }
    for (const [index, username] of accounts.entries()) {
        if (index === 0 || accounts[index - 1] !== username) await login(username);
        const list = await get('/dashboard/applications/data'); assert.equal(list.status, 200);
        const row = list.body.applications.find(a => index === 1 ? a.recipient_agreement?.status === 'accepted' : !a.recipient_monitoring?.eligible);
        assert.ok(row, 'An existing ' + (index ? 'accepted award' : 'active application') + ' is required.');
        const url = '/dashboard/applications/' + row.id;
        const response = await get(url + '/data'); assert.equal(response.status, 200);
        const detail = response.body.detail; report.verified_application_ids.push(row.id);
        await navigate(url, "!!document.querySelector('[data-application-detail-header]')");
        await inspect(detail);
        if (index === 0) {
            await captureHeader('application-header'); await shot('application-header-desktop');
            for (const width of [1024, 768, 390, 320]) {
                await client.send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: width < 640 });
                await inspect(detail);
                assert.equal(await client.evaluate("getComputedStyle(document.querySelector('[data-application-cover]')).display==='none'"), width < 1024);
                if (width === 390) await shot('application-header-mobile');
            }
            await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
            for (const item of detail.navigation.filter(n => n.key !== 'summary')) {
                await navigate(item.href, "!!document.querySelector('[data-application-detail-header]') && document.getElementById('app').dataset.applicationSection===" + JSON.stringify(item.key));
                await inspect(detail);
                assert.ok((await client.evaluate("document.querySelector('[data-applicant-record-tabs] [aria-current=page]').innerText")).includes(item.label));
            }
        } else {
            assert.equal(await client.evaluate("!!document.querySelector('[data-monitoring-detail-header]')"), false);
            await captureHeader('application-header-selected');
            await client.evaluate("document.querySelector('[data-application-program-logo]').dispatchEvent(new Event('error'))");
            assert.equal(await client.evaluate("getComputedStyle(document.querySelector('[data-application-program-logo]')).display"), 'none');
            assert.equal(await client.evaluate("document.querySelector('[data-application-logo-frame] i').getBoundingClientRect().width>0"), true);
        }
    }
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Shared application masthead: real status, submission date, separate program logo/cover, four facts, all sections, accepted award access, fallback logo and desktop/mobile layouts. No business writes submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
