import { readFile } from 'node:fs/promises';

const origin = process.env.PROVIDER_REVIEW_SMOKE_ORIGIN || 'http://127.0.0.1:8156';
const hosted = origin.startsWith('https:');
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace("accountability-hosted-part6';", `provider-document-review${hosted ? '-hosted' : ''}';`)
    .replaceAll('accountability-part6-hosted-browser-20261103.json', `provider-document-review-browser${hosted ? '-hosted' : ''}.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    await login('platform.reviews');
    const queue = await get('/admin/workspaces/reviews/providers/data?status=all');
    assert.equal(queue.status,200);
    const provider = queue.body.items.find(row=>/dalisay/i.test(row.provider_name || row.name));
    assert.ok(provider,'Prepared provider verification demo');
    const id = provider.id;
    await navigate('/admin/workspaces/reviews/providers/'+id,"!!document.querySelector('[data-review-provider-document]')");
    await shot('01-provider-document-queue');
    assert.equal(await client.evaluate("document.querySelector('form button[type=submit]').disabled"),true);
    await client.evaluate("document.querySelector('[data-review-provider-document]').click()");
    await wait(()=>client.evaluate("!!document.querySelector('[data-provider-document-review]')"),'document review modal');
    await audit('[data-provider-document-review]');
    await delay(2500);
    await client.evaluate("(()=>{const s=document.querySelector('[data-provider-document-review] select');s.value='approved';s.dispatchEvent(new Event('change',{bubbles:true}))})()");
    assert.equal(await client.evaluate("document.querySelector('[data-provider-document-review] button[type=submit]').disabled"),true);
    await shot('02-provider-proof-review');
    await client.evaluate("(()=>{const e=document.querySelector('[data-provider-document-review] input[type=checkbox]');e.click()})()");
    assert.equal(await client.evaluate("document.querySelector('[data-provider-document-review] button[type=submit]').disabled"),false);
    await client.evaluate("(()=>{const s=document.querySelector('[data-provider-document-review] select');s.value='rejected';s.dispatchEvent(new Event('change',{bubbles:true}))})()");
    assert.equal(await client.evaluate("document.querySelector('[data-provider-document-review] button[type=submit]').disabled"),true);
    await client.evaluate("(()=>{const e=document.querySelector('[data-provider-document-review] textarea');e.value='Please upload a readable registration certificate.';e.dispatchEvent(new Event('input',{bubbles:true}))})()");
    assert.equal(await client.evaluate("document.querySelector('[data-provider-document-review] button[type=submit]').disabled"),false);
    await shot('03-replacement-request-unsaved');
    await click('Cancel',"document.querySelector('[data-provider-document-review]')");
    await wait(()=>client.evaluate("!document.querySelector('[data-provider-document-review]')"),'cancelled review');
    await client.evaluate("document.querySelector('form').scrollIntoView({block:'end'})");
    await shot('04-final-provider-decision');
    await login('dalisay');
    await navigate('/provider/workspaces/organization-profile/verification',"document.body.innerText.includes('Verification proof') && !!document.querySelector('button[title=\"Replace document\"]')");
    await client.evaluate("document.querySelector('button[title=\"Replace document\"]').click()");
    await wait(()=>client.evaluate("document.body.innerText.includes('The new file needs its own review.')"),'replacement upload preparation');
    assert.notEqual(await client.evaluate("document.querySelector('input[type=file]').closest('form').querySelector('select').value"),'');
    await shot('05-provider-replacement-upload');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.providerId=id;report.passed=true;
    console.log('PASS Provider document review, approval gating, comparison acknowledgement, replacement form, cancellation, and modal accessibility. No business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message); process.exitCode = 1; }
