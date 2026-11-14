import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-scoring-preview-20261114';
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json')
    .replace('let control, client, contextId;', 'let control, client, contextId, programFixture = null;')
    .replace("            const blocked = !['GET', 'HEAD', 'OPTIONS'].includes(request.method)", String.raw`
            if (programFixture && request.method === 'GET' && route === '/provider/scholarships/8') {
                void client.send('Fetch.fulfillRequest', {requestId, responseCode:200, responseHeaders:[{name:'Content-Type',value:'application/json'}], body:Buffer.from(JSON.stringify({scholarship:programFixture})).toString('base64')});
                return;
            }
            const blocked = !['GET', 'HEAD', 'OPTIONS'].includes(request.method)`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const openSelection = () => navigate('/provider/programs/8/edit/selection', "!!document.querySelector('[data-program-scoring]')");
    const weights = () => client.evaluate("Object.fromEntries([...document.querySelectorAll('[data-scoring-stage]')].map(e=>[e.dataset.scoringStage,Number(e.lastElementChild.innerText.replace('%',''))]))");
    const state = () => client.evaluate("document.querySelector('[data-scoring-state]').innerText");
    const toggle = async(label,count) => {
        await client.evaluate("(()=>{const b=[...document.querySelector('[data-program-scoring]').parentElement.querySelectorAll('button')].find(b=>b.querySelector('span.block')?.textContent==="+JSON.stringify(label)+");if(!b)throw new Error('Missing stage button');b.click()})()");
        await wait(async()=>Object.keys(await weights()).length===count,'stage preview update');
    };
    await login('nuvareya.programs');
    await openSelection();
    const original=(await get('/provider/scholarships/8')).body.scholarship;
    assert.ok(original.selection_scoring_policy,'Existing demo intake has a fixed scoring plan.');
    assert.equal(await state(),'Fixed for this intake');
    assert.deepEqual(await weights(),original.selection_scoring_policy.weights);
    assert.ok(await client.evaluate("document.querySelector('[data-program-scoring]').innerText.includes('You still make the final decision')"));
    await client.evaluate("document.querySelector('[data-program-scoring]').scrollIntoView({block:'center'})");
    await delay(200);
    const clip = await client.evaluate("(()=>{const r=document.querySelector('[data-program-scoring]').getBoundingClientRect();return{x:r.x-14,y:r.y+scrollY-6,width:r.width+28,height:r.height+22,scale:1}})()");
    const screenshot=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});
    const file=path.join(folder,'selection-dss-score-summary.png');await writeFile(file,Buffer.from(screenshot.data,'base64'));report.screenshots.push(file);
    await toggle('Exam',Object.keys(original.selection_scoring_policy.weights).length);
    assert.deepEqual(await weights(),original.selection_scoring_policy.weights);
    assert.match(await client.evaluate("document.querySelector('[data-program-scoring]').innerText"),/Changing its stage path requires a new intake/);

    // Read-only response fixtures exercise a new plan without modifying the demo database.
    programFixture={...original,status:'draft',selection_scoring_policy:null,applications_count:0,selection_stages:['screening','formal_application','decision']};
    await login('nuvareya.programs');await openSelection();
    const presets=await client.evaluate("JSON.parse(document.getElementById('app').dataset.scoringPresets)");
    assert.equal(await state(),'Plan preview');assert.deepEqual(await weights(),presets.screening.weights);
    await toggle('Exam',2);assert.deepEqual(await weights(),presets.exam.weights);
    await toggle('Interview',3);assert.deepEqual(await weights(),presets['exam+interview'].weights);
    await toggle('Exam',2);assert.deepEqual(await weights(),presets.interview.weights);
    await toggle('Interview',1);assert.deepEqual(await weights(),presets.screening.weights);

    programFixture={...programFixture,applications_count:2};
    await login('nuvareya.manager');await openSelection();
    assert.equal(await state(),'Existing intake');assert.deepEqual(await weights(),{});
    assert.match(await client.evaluate("document.querySelector('[data-program-scoring]').innerText"),/without adding a numeric ranking score/);
    programFixture=null;
    assert.deepEqual((await get('/provider/scholarships/8')).body.scholarship.selection_scoring_policy,original.selection_scoring_policy);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Server-sourced stage weights, live plan changes, fixed-intake preservation, legacy intake wording, coordinator and manager access. No business records saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
