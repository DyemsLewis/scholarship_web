import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-save-protection-20261114';
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const status = () => client.evaluate("document.querySelector('[data-program-save-status]')?.innerText.trim()");
    const setTitle = value => client.evaluate("(()=>{const e=document.querySelector('.program-builder-form input[type=text]');e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    const unloadPrevented = () => client.evaluate("(()=>{const e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented})()");
    const dismissAlert = () => client.evaluate("[...document.querySelector('[role=alertdialog]').querySelectorAll('button')].at(-1).click()");
    await login('nuvareya.programs');
    await navigate('/provider/programs/create', "!!document.querySelector('.program-builder-form')");
    assert.equal(await status(), 'Not saved yet');
    assert.equal(await unloadPrevented(), false);
    assert.ok(await client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Save draft')"));
    await setTitle('Unsaved browser test');
    assert.equal(await status(), 'Unsaved changes');
    assert.equal(await unloadPrevented(), true);
    await client.evaluate("[...document.querySelectorAll('a')].find(a=>a.innerText.includes('Back to programs')).click()");
    await wait(() => client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Leave without saving?')"), 'leave confirmation');
    await click('Keep editing', "document.querySelector('[role=alertdialog]')");
    assert.equal(await client.evaluate("document.querySelector('.program-builder-form input[type=text]').value"), 'Unsaved browser test');
    await setTitle('');
    assert.equal(await unloadPrevented(), false);

    await navigate('/provider/programs/8/edit/basics', "!!document.querySelector('.program-builder-form')");
    assert.equal(await status(), 'All changes saved');
    const fixture = (await get('/provider/scholarships/8')).body.scholarship;
    assert.equal(fixture.status, 'draft');
    await setTitle(fixture.title + ' - unsaved');
    await client.evaluate('window.__savedFixture=' + JSON.stringify({ ...fixture, title: fixture.title + ' - unsaved' }));
    await client.evaluate(String.raw` + '`' + String.raw`(() => {
        window.__saveFailure = true;
        const realPost = window.axios.post.bind(window.axios);
        window.axios.post = async (url, ...args) => {
            if (url !== '/provider/scholarships/8') return realPost(url, ...args);
            await new Promise(resolve => setTimeout(resolve, 250));
            if (window.__saveFailure) throw { response: { data: { message: 'Save failed for browser test.' } } };
            return { data: { scholarship: window.__savedFixture, message: 'Program saved.' } };
        };
    })()` + '`' + String.raw`);
    await click('Save draft');
    await wait(async () => await status() === 'Changes not saved', 'failed save state');
    assert.equal(await client.evaluate("document.querySelector('.program-builder-form input[type=text]').value"), fixture.title + ' - unsaved');
    assert.equal(await unloadPrevented(), true);
    await dismissAlert();
    await client.evaluate('window.__saveFailure=false');
    await click('Save draft');
    await wait(async () => await status() === 'All changes saved', 'successful save state');
    assert.equal(await unloadPrevented(), false);
    await dismissAlert();

    await navigate('/provider/programs/1/edit/basics', "!!document.querySelector('.program-builder-form')");
    assert.equal(await status(), 'All changes saved');
    assert.equal(await client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Save and continue')"), false);
    assert.ok(await client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Continue')"));
    await client.evaluate("[...document.querySelectorAll('nav[aria-label=\"Program builder steps\"] button')].find(b=>b.innerText.includes('Support')).click()");
    await client.evaluate("[...document.querySelectorAll('.program-builder-form article button')].find(b=>b.innerText.trim()==='Edit').click()");
    await wait(() => client.evaluate("!!document.querySelector('[aria-labelledby=benefit-dialog-title]')"), 'benefit editor');
    await client.evaluate("const e=document.querySelector('[aria-labelledby=benefit-dialog-title] input');e.value+=' changed';e.dispatchEvent(new Event('input',{bubbles:true}));");
    assert.equal(await unloadPrevented(), true);
    await click('Cancel', "document.querySelector('[aria-labelledby=benefit-dialog-title]')");
    assert.equal(await status(), 'All changes saved');
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.blockedWrites, []);
    assert.deepEqual(report.serverErrors, []);
    report.passed = true;
    console.log('PASS Save status, keep-editing confirmation, refresh guard, failed/successful mock saves, pending benefits and published navigation. No program records changed.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
