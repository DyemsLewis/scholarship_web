import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, "const folder = 'storage/qa';")
    .replaceAll('accountability-hosted-part6', 'provider-program-workspaces-local')
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', 'storage/qa/provider-program-workspaces-local.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + String.raw`
    await login('marilag');
    const directory = await get('/provider/programs/data?filter=published&sort=title');
    assert.equal(directory.status, 200);
    const program = directory.body.programs[0];
    assert.ok(program, 'A published program exists in the local demo.');
    const recordPath = '/provider/programs/' + program.id;
    const selector = 'nav[aria-labelledby=program-workspaces-heading]';
    await navigate(recordPath, 'document.querySelectorAll("' + selector + ' a").length === 6');
    const links = await client.evaluate('[...document.querySelectorAll("' + selector + ' a")].map(a=>a.href)');
    assert.deepEqual(links.map(link=>new URL(link).searchParams.get('program_id')), Array(6).fill(String(program.id)));
    assert.equal(await client.evaluate("location.pathname.startsWith('/provider/r/')"), true);
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'program logo');
    await shot('provider-part-3-program-workspaces');

    const context = 'document.querySelector("[data-provider-program-context]")';
    const clickLink = (label, scope = 'document') => client.evaluate('(()=>{const link=[...'+scope+'.querySelectorAll("a")].find(a=>a.innerText.includes('+JSON.stringify(label)+'));if(!link)throw new Error("Link not found");link.click()})()');
    await clickLink('Application review', 'document.querySelector("'+selector+'")');
    await wait(() => client.evaluate('location.pathname.endsWith("/reviews/all") && !!'+context+' && !document.body.innerText.includes("Updating results...")'), 'filtered screening queue');
    assert.ok((await client.evaluate(context+'.innerText')).includes(program.title));
    await wait(() => client.evaluate('[...document.images].every(img=>img.complete)'), 'applicant avatar');
    await shot('provider-part-3-filtered-queue');
    await clickLink('My reviews', 'document.querySelector('+JSON.stringify('nav[aria-label="Application review queues"]')+')');
    await wait(() => client.evaluate('location.pathname.endsWith("/reviews/assigned") && !!'+context), 'scoped review tab');
    assert.equal(await client.evaluate('new URLSearchParams(location.search).get("program_id")'), String(program.id));
    await clickLink('Open program', context);
    await wait(() => client.evaluate('!!document.querySelector("'+selector+'")'), 'return to program record');
    assert.equal(await client.evaluate('[...document.querySelectorAll("a")].find(a=>a.innerText.trim()==="Back to queue").getAttribute("href")'), '/provider/workspaces/reviews/assigned?program_id='+program.id+'&page=1');

    for (const link of links.slice(1)) {
        const url = new URL(link);
        await navigate(url.pathname+url.search, '!!'+context+' && !document.body.innerText.includes("Updating results...")');
        assert.ok((await client.evaluate(context+'.innerText')).includes(program.title));
    }
    assert.deepEqual(report.errors, []);
    assert.deepEqual(report.serverErrors, []);
    assert.deepEqual(report.blockedWrites, []);
    report.passed = true;
    console.log('PASS All six program shortcuts, scoped queue tabs, and the return link. No records changed.');
` + source.slice(end);
await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
