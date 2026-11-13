import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-guided-eligibility-20261114';
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const field = (selector, value) => client.evaluate("(()=>{const e=document.querySelector("+JSON.stringify(selector)+");e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event('change',{bubbles:true}));})()");
    const pickerText = id => client.evaluate('document.querySelector('+JSON.stringify('[data-eligibility-picker='+id+']')+').innerText');
    await login('nuvareya.programs');
    await navigate('/provider/programs/8/edit/eligibility', "!!document.querySelector('#program-eligible-courses')");
    assert.equal(await client.evaluate("document.querySelector('[data-program-save-status]').innerText.trim()"), 'All changes saved');
    assert.equal(await client.evaluate("document.querySelector('#program-eligible-courses').tagName"), 'SELECT');
    assert.equal(await client.evaluate("document.querySelector('#program-eligible-years').tagName"), 'SELECT');
    assert.match(await pickerText('program-eligible-courses'), /STEM/);
    assert.match(await pickerText('program-eligible-years'), /Grade 12/);
    assert.match(await pickerText('program-eligible-locations'), /Rizal/);
    assert.equal(await client.evaluate("document.documentElement.scrollWidth <= innerWidth+2"), true);
    const clip = await client.evaluate("(()=>{const root=document.querySelector('[data-eligibility-picker=program-eligible-courses]').parentElement.parentElement,r=root.getBoundingClientRect();const right=Math.max(r.right,...[...root.querySelectorAll('input,select,button')].map(e=>e.getBoundingClientRect().right));return {x:r.x-12,y:r.y+scrollY-12,width:right-r.x+24,height:r.height+24,scale:1}})()");
    const screenshot = await client.send('Page.captureScreenshot', {format:'png',captureBeyondViewport:true,clip});
    const file = path.join(folder,'guided-eligibility-inputs.png');
    await writeFile(file, Buffer.from(screenshot.data,'base64')); report.screenshots.push(file);
    await field('#program-eligible-courses','ABM');
    await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-courses] [data-add-option]').click()");
    assert.match(await pickerText('program-eligible-courses'), /ABM/);
    assert.match(await pickerText('program-eligible-courses'), /STEM/);
    await client.evaluate("const root=document.querySelector('[data-eligibility-picker=program-eligible-courses]');root.querySelector('details').open=true;const e=root.querySelector('input');e.value='BS Applied Learning';e.dispatchEvent(new Event('input',{bubbles:true}));");
    await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-courses] details button').click()");
    assert.match(await pickerText('program-eligible-courses'), /BS Applied Learning/);
    await client.evaluate("[...document.querySelectorAll('.program-builder-form label')].find(e=>e.innerText.trim()==='College / University').querySelector('input').click()");
    assert.ok(await client.evaluate("[...document.querySelector('#program-eligible-courses').options].some(o=>o.value==='BS Information Technology')"));
    assert.match(await pickerText('program-eligible-courses'), /BS Applied Learning/);
    const coverageBefore = await pickerText('program-eligible-locations');
    await field('#program-eligible-locations','1300000000');
    await wait(()=>client.evaluate("document.querySelector('#program-coverage-province').options.length>1"),'province options');
    assert.match(await pickerText('program-eligible-locations'), /Rizal/);
    await click('Add region', "document.querySelector('[data-eligibility-picker=program-eligible-locations]')");
    assert.match(await pickerText('program-eligible-locations'), /NCR/);
    await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-locations] [data-open-rule]').click()");
    assert.equal(await client.evaluate("document.querySelectorAll('[data-eligibility-picker=program-eligible-locations] li').length"),0);
    await click('Add region', "document.querySelector('[data-eligibility-picker=program-eligible-locations]')");
    assert.equal(await client.evaluate("document.querySelectorAll('[data-eligibility-picker=program-eligible-locations] li').length"),1);
    assert.match(await pickerText('program-eligible-locations'), /NCR/);
    assert.deepEqual(report.errors,[]); assert.deepEqual(report.serverErrors,[]); assert.deepEqual(report.blockedWrites,[]);
    report.passed = true;
    console.log('PASS Guided selections, saved/custom value preservation, education options, explicit nationwide changes and unchanged payload format. No records saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message); process.exitCode=1; }
