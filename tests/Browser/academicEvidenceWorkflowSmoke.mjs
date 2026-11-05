import { readFile } from 'node:fs/promises';

const base = process.env.ACADEMIC_REVIEW_SMOKE_ORIGIN || 'http://127.0.0.1:8156';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(base)};`)
    .replace("accountability-hosted-part6';", "academic-profile-review';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', `academic-profile-browser-${base.startsWith('https:') ? 'hosted' : 'local'}.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    await login('platform.reviews');
    await navigate('/admin/workspaces/reviews/applicants/39', "document.querySelectorAll('#supporting-evidence tbody tr').length>0");
    await client.evaluate("document.querySelector('#supporting-evidence').scrollIntoView({block:'start'})");
    await shot('01-applicant-evidence-review');
    await client.evaluate("(()=>{const row=[...document.querySelectorAll('#supporting-evidence tbody tr')].find(e=>/academic|grade/i.test(e.querySelector('td').innerText));if(!row)throw new Error('Academic evidence missing');row.querySelector('button').click()})()");
    await wait(()=>client.evaluate("!!document.querySelector('.evidence-review-dialog')"), 'academic review dialog');
    await client.evaluate("document.querySelector('.evidence-review-dialog input[value=approved]').click()");
    await wait(()=>client.evaluate("!!document.querySelector('[data-academic-results]')"), 'academic results controls');
    await client.evaluate("document.querySelector('[data-academic-results]').scrollIntoView({block:'center',behavior:'instant'})");
    await delay(400);
    await shot('02-academic-results-review');
    const clip = await client.evaluate("(()=>{const r=document.querySelector('#evidence-decision').getBoundingClientRect();return {x:r.x-12+scrollX,y:r.y-8+scrollY,width:r.width+24,height:r.height+16,scale:1}})()");
    const focused = await client.send('Page.captureScreenshot', { format: 'png', clip });
    const focusedPath = path.join(folder, '02a-academic-results-controls.png');
    await writeFile(focusedPath, Buffer.from(focused.data, 'base64')); report.screenshots.push(focusedPath);
    const read = () => client.evaluate("(()=>{const section=document.querySelector('[data-academic-results]');return {scale:section.querySelector('select').value,result:section.querySelector('input')?.value,disabled:document.querySelector('button[form=evidence-decision]').disabled}})()");
    await client.evaluate("(()=>{const root=document.querySelector('.evidence-review-dialog');root.querySelector('input[type=checkbox]').click();const i=root.querySelector('[data-academic-results] input');i.value='101';i.dispatchEvent(new Event('input',{bubbles:true}))})()");
    assert.equal((await read()).disabled,true);
    await client.evaluate("(()=>{const e=document.querySelector('[data-academic-results] input');e.value='92';e.dispatchEvent(new Event('input',{bubbles:true}))})()");
    assert.equal((await read()).disabled,false);
    await client.evaluate("document.querySelector('[aria-label=\"Close evidence review\"]').click()");
    await client.evaluate("document.querySelector('#final-profile-verification').scrollIntoView({block:'center'})");
    assert.deepEqual(await client.evaluate("[...document.querySelector('#final-profile-verification').querySelectorAll('select, input[type=number], textarea')].map(e=>e.tagName)"), ['SELECT','TEXTAREA']);
    await shot('03-final-profile-decision');
    await login('platform.manager');
    await navigate('/admin/workspaces/portal/reviews', "document.body.innerText.includes('Read-only oversight')");
    await shot('04-manager-profile-oversight');
    assert.equal(await client.evaluate("!!document.querySelector('#final-profile-verification') || !!document.querySelector('[data-academic-results]')"),false);
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Academic result controls, range validation, profile-only final decision, manager read-only oversight. No reviews saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
