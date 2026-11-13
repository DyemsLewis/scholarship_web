import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/eligibility-checklist-20261114';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
if (process.env.ELIGIBILITY_CAPTURE_ORIGIN) source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(process.env.ELIGIBILITY_CAPTURE_ORIGIN)};`);
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json')
    .replace('const route = new URL(request.url).pathname;', String.raw`
            const route = new URL(request.url).pathname;
            if (request.method === 'POST' && /^\/dashboard\/scholarships\/\d+\/application-start$/.test(route)) {
                void client.send('Fetch.fulfillRequest', { requestId, responseCode: 204 }).catch(() => {});
                return;
            }
    `);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness changed.');
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
source = source.slice(0, start) + `const expectedAsset = ${JSON.stringify(manifest['resources/js/app.js'].file)};\n` + String.raw`
    const response = await fetch(origin + '/login');
    assert.equal(response.status, 200);
    assert.ok((await response.text()).includes(expectedAsset), 'Latest build is live.');
    await login('cohort.learner.apply');
    await navigate('/dashboard/applications?scholarship=1', "Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Continue to Eligibility' && !b.disabled)");
    await click('Continue to Eligibility');
    await wait(() => client.evaluate("Array.from(document.querySelectorAll('button')).some(b=>b.innerText.trim()==='Continue to Requirements')"), 'Step 2');
    report.cards = await client.evaluate(String.raw` + '`' + String.raw`(() => {
        const list = document.querySelector('[data-application-eligibility-rules]');
        if (!list) throw new Error('Eligibility checklist missing.');
        list.scrollIntoView({ block: 'center' });
        return [...list.children].map(e => ({
            label: e.querySelector('dt').innerText,
            compact: e.classList.contains('py-2.5') && !e.classList.contains('h-full'),
            icon: e.firstElementChild.getBoundingClientRect().height,
            height: e.getBoundingClientRect().height,
            status: e.lastElementChild.innerText,
        }));
    })()` + '`' + String.raw`);
    assert.ok(report.cards.length > 0);
    assert.ok(report.cards.every(e => e.compact && e.icon === 24 && e.status));
    assert.equal(await client.evaluate('document.documentElement.scrollWidth <= innerWidth + 2'), true);
    const clip = await client.evaluate("(()=>{const d=document.querySelector('[data-application-eligibility-rules]'),r=d.getBoundingClientRect(),h=d.previousElementSibling.getBoundingClientRect();return {x:r.x-12,y:h.y+scrollY,width:r.width+24,height:r.bottom-h.top+12,scale:1}})()");
    const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip });
    const screenshotFile = path.join(folder, 'step-2-eligibility-checklist.png');
    await writeFile(screenshotFile, Buffer.from(screenshot.data, 'base64'));
    report.screenshots.push(screenshotFile);
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS Hosted Step 2 compact eligibility cards; no application submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
