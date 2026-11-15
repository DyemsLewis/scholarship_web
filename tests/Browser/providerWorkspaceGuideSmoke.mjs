import { readFile } from 'node:fs/promises';

const origin = process.env.GUIDE_ORIGIN || 'https://findscholarship.online';
const prefix = process.env.GUIDE_ACCOUNT_PREFIX || 'orivanta';
const label = origin.includes('127.0.0.1') ? 'local' : 'hosted';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `workspace-guide-${label}-part1`)
    .replace('b.click()})()`);', 'b.focus();b.click()})()`);')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/workspace-guide-${label}-browser-20261115.json`)
    .replace("!['/login', '/hcdn-cgi/jschallenge-validate'].includes(route)", "!['/login', '/hcdn-cgi/jschallenge-validate', '/provider/workspace-guides/programs/progress'].includes(route)");
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = `
    const dialogSelector = '.workspace-guide-dialog';
    const visible = () => client.evaluate("!!document.querySelector('.workspace-guide-dialog')");
    const guideClick = async label => {
        await wait(() => client.evaluate("[...document.querySelectorAll('.workspace-guide-dialog button')].some(b=>b.innerText.trim()===" + JSON.stringify(label) + ")"), 'guide button ' + label);
        return click(label, "document.querySelector('.workspace-guide-dialog')");
    };
    const trackStatus = async status => wait(async () => (await get('/provider/workspace-guides/programs')).body.status === status, 'saved ' + status);
    await login('${prefix}.programs');
    await wait(() => client.evaluate("document.querySelector('#coordinator-dashboard-title') && [...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Workspace guide')"), 'Programs dashboard');
    const initial = await get('/provider/workspace-guides/programs'); assert.equal(initial.status, 200);
    if (!await visible()) await click('Workspace guide');
    await wait(visible, 'guide'); await audit(dialogSelector);
    await wait(() => client.evaluate("document.querySelector('.workspace-guide-dialog')?.innerText.includes('Start with a draft')"), 'first step');
    await shot('programs-guide-start');
    await trackStatus(initial.body.status || 'seen');
    await click('Skip guide'); await wait(async () => !await visible(), 'skip');
    if (initial.body.status !== 'completed') await trackStatus('dismissed');
    await navigate('/provider/programs', "!!document.querySelector('#program-directory-title') && !!document.querySelector('.program-summary[aria-busy=false]')");
    await delay(600); assert.equal(await visible(), false, 'Guide must not interrupt each visit');
    const before = await client.evaluate("(()=>{const r=document.querySelector('#program-directory-title').getBoundingClientRect();return {x:r.x,y:r.y,w:r.width}})()");
    await click('Workspace guide'); await wait(visible, 'reopen'); await audit(dialogSelector);
    const during = await client.evaluate("(()=>{const r=document.querySelector('#program-directory-title').getBoundingClientRect();return {x:r.x,y:r.y,w:r.width}})()");
    assert.deepEqual(before, during, 'Background must not shift');
    await guideClick('Next'); await wait(() => client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes('Build the offer and rules')"), 'step two');
    await guideClick('Back'); assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog').innerText.includes('Start with a draft')"), true);
    await guideClick('Next'); await guideClick('Next'); await guideClick('Next'); await audit(dialogSelector);
    await shot('programs-guide-handoff'); await click('Got it'); await trackStatus('completed');
    await navigate('/provider/programs/create', "!!document.querySelector('#program-builder-title')");
    await delay(500); assert.equal(await visible(), false, 'Builder must not auto-open the guide');
    await client.evaluate("(()=>{const e=document.querySelector('input[maxlength=\\\"255\\\"]');if(!e)throw new Error('Missing program title input');e.value='Unsaved tutorial QA';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    const formBefore = await client.evaluate("[...document.querySelectorAll('input,textarea,select')].map(e=>[e.id,e.value])");
    await click('Workspace guide'); await wait(visible, 'builder help'); await audit(dialogSelector);
    await client.send('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape'});
    await wait(async () => !await visible(), 'Escape');
    assert.deepEqual(await client.evaluate("[...document.querySelectorAll('input,textarea,select')].map(e=>[e.id,e.value])"), formBefore);
    assert.equal(await client.evaluate("document.activeElement?.innerText.trim()"), 'Workspace guide');
    await login('${prefix}.manager');
    await navigate('/provider/programs', "!!document.querySelector('#program-directory-title')");
    await delay(500); assert.equal(await visible(), false, 'No manager workspace popup');
    assert.equal((await get('/provider/workspace-guides/programs')).body.auto_offer, false);
    await click('Workspace guide'); await wait(visible, 'manager manual guide'); await audit(dialogSelector); await click('Skip guide');
    await login('${prefix}.reviewer');
    assert.equal((await get('/provider/workspace-guides/programs')).status, 403);
    if (await visible()) assert.equal(await client.evaluate("document.querySelector('.workspace-guide-dialog h2').innerText==='Programs workspace'"), false);
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true; console.log('PASS Programs guide: tracking, skip, completion, reopen, focus, unchanged background/form, manager and reviewer boundaries. Only tutorial progress was written.');
`;
source = source.slice(0, start) + cases + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
