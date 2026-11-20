import { readFile } from 'node:fs/promises';

const origin = process.env.PROGRAM_ELIGIBILITY_ORIGIN || 'http://127.0.0.1:8000';
const hosted = origin.startsWith('https:');
const folder = process.env.PROGRAM_ELIGIBILITY_OUTPUT || 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/provider-eligibility-' + (hosted ? 'hosted' : 'local') + '-20261115';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < start) throw new Error('Browser harness structure changed.');
source = source.slice(0, start) + `
    const username = ${JSON.stringify(hosted ? 'orivanta.programs' : 'nuvareya.programs')};
` + String.raw`
    const field = (selector,value,event='input') => client.evaluate("(()=>{const e=document.querySelector("+JSON.stringify(selector)+");e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event("+JSON.stringify(event)+",{bubbles:true}));})()");
    const summaryField = () => client.evaluate("document.querySelector('#program-eligibility-summary').value");
    const openSummary = async () => {
        await client.evaluate("(()=>{const b=document.querySelector('#program-eligibility-summary-edit');b.focus({preventScroll:true});b.click()})()");
        await wait(()=>client.evaluate("!!document.querySelector('#program-summary-editor-options')"),'summary modal');
    };
    const summary = async () => {
        await openSummary();
        const value = await summaryField();
        await modalAction('program-summary-editor','Cancel');
        return value;
    };
    async function openModal(id) {
        await client.evaluate("(()=>{const b=document.getElementById("+JSON.stringify(id)+");b.focus({preventScroll:true});b.click()})()");
        await wait(()=>client.evaluate("!!document.querySelector('[data-eligibility-options-for="+id+"]')"),'options modal '+id);
    }
    async function modalAction(id,label) {
        await client.evaluate("(()=>{const d=document.querySelector('[data-eligibility-options-for="+id+"]'),b=[...d.querySelectorAll('button')].find(e=>e.innerText.trim()==="+JSON.stringify(label)+");if(!b)throw new Error('Missing modal action');b.focus({preventScroll:true});b.click()})()");
    }
    const checkbox = label => client.evaluate("document.querySelector('[data-checkbox-picker=program-study-levels]').innerText.includes("+JSON.stringify(label)+")");
    async function toggleEducation(label) {
        await openModal('program-study-levels');
        await client.evaluate("[...document.querySelectorAll('[data-eligibility-options-for=program-study-levels] label')].find(e=>e.innerText.trim()==="+JSON.stringify(label)+").querySelector('input').click()");
        await modalAction('program-study-levels','Apply selections');
    }
    const persisted = p => Object.fromEntries(['eligibility','eligibility_rule_modes','education_eligibility_policy','eligibility_conditions','eligible_education_levels','eligible_courses','eligible_year_levels','eligible_locations','eligible_school_types','income_requirement','minimum_grade_scale','minimum_gwa','exclude_current_recipients','status','updated_at'].map(key=>[key,p[key]]));
    await login(username);
    await navigate('/provider/programs/create', "!!document.querySelector('.program-builder-form')");
    await client.evaluate("[...document.querySelectorAll('[aria-label=\"Program builder steps\"] button')].find(b=>b.innerText.includes('Eligibility')).click()");
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Save the basics first')"),'create draft-first safeguard');
    await click('OK',"document.querySelector('[role=alertdialog]')");
    const list=await get('/provider/programs/data?filter=all');assert.equal(list.status,200);
    let before;
    for(const row of list.body.programs.filter(p=>p.status.value!=='pending_review')) {
        const response=await get('/provider/scholarships/'+row.id);assert.equal(response.status,200);
        const p=response.body.scholarship;
        if(p.eligible_education_levels?.includes('senior_high_school')&&p.eligible_courses?.includes('STEM')) { before=p;break; }
    }
    assert.ok(before,'An editable senior-high program is required for the unsaved compatibility checks.');
    report.program_id=before.id;
    await navigate('/provider/programs/'+before.id+'/edit/eligibility',"!!document.querySelector('[data-program-eligibility-editor]')");
    assert.equal(await client.evaluate("document.querySelector('[data-program-save-status]').innerText.trim()"),'All changes saved');
    assert.equal(await summary(),before.eligibility);
    assert.equal(await client.evaluate("!!document.querySelector('#program-eligibility-summary')"),false);
    assert.equal(await client.evaluate("[...document.querySelectorAll('[data-eligibility-picker] li')].every(e=>!!e.closest('[data-selection-field]'))"),true);
    assert.equal(await client.evaluate("[...document.querySelectorAll('[data-eligibility-mode]')].every(e=>e.getBoundingClientRect().height===32)"),true);
    await openSummary();
    if(!(await client.evaluate("document.querySelector('#program-eligibility-summary').readOnly"))) await click('Use rule-based summary');
    await modalAction('program-summary-editor','Apply changes');
    for(const width of [1440,1280]) {
        await client.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
        assert.equal(await client.evaluate("document.documentElement.scrollWidth<=innerWidth+2"),true);
        assert.equal(await client.evaluate("(()=>{const root=document.querySelector('[data-program-eligibility-editor]'),bounds=root.getBoundingClientRect();return [...root.querySelectorAll('input,select,textarea')].filter(e=>e.getBoundingClientRect().width).every(e=>e.getBoundingClientRect().right<=bounds.right+1)})()"),true);
    }
    await client.send('Emulation.setDeviceMetricsOverride',{width:1440,height:3000,deviceScaleFactor:1,mobile:false});
    await client.evaluate('document.fonts.ready');await client.evaluate("window.scrollTo({top:0,behavior:'instant'})");await delay(200);
    const clip=await client.evaluate("(()=>{const r=document.querySelector('[data-program-eligibility-editor]').getBoundingClientRect();return {x:r.left,y:r.top+scrollY,width:r.width,height:r.height,scale:1}})()");
    const image=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});
    const file=path.join(folder,'provider-program-eligibility.png');await writeFile(file,Buffer.from(image.data,'base64'));report.screenshots.push(file);
    await client.send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    const layout=()=>client.evaluate("(()=>{const r=document.querySelector('[data-program-eligibility-editor]').getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,scroll:scrollY}})()");
    const oldSummary=await summary(), closedLayout=await layout();
    await openModal('program-eligible-courses');
    assert.deepEqual(await layout(),closedLayout);
    assert.deepEqual(await client.evaluate("(()=>{const d=document.querySelector('[data-eligibility-options-for=program-eligible-courses]'),r=d.getBoundingClientRect();return {fits:r.top>=0&&r.bottom<=innerHeight+1,footer:d.querySelector('footer').getBoundingClientRect().bottom<=innerHeight+1,focus:d.contains(document.activeElement),locked:document.documentElement.style.overflowY==='hidden'}})()"),{fits:true,footer:true,focus:true,locked:true});
    await shot('eligibility-course-modal');
    const chooseQuick=()=>client.evaluate("(()=>{const b=document.querySelector('[data-eligibility-options-for=program-eligible-courses] [data-quick-option]:not(:disabled)');if(!b)throw new Error('No common course shortcut available');const value=b.dataset.quickOption;b.click();return value})()");
    await chooseQuick();
    await modalAction('program-eligible-courses','Cancel');
    await wait(()=>client.evaluate("!document.querySelector('[data-eligibility-options-for=program-eligible-courses]')"),'course cancellation');
    assert.equal(await summary(),oldSummary);assert.deepEqual(await layout(),closedLayout);
    assert.equal(await client.evaluate('document.activeElement.id'),'program-eligible-courses');
    await openModal('program-eligible-courses');const quickChoice=await chooseQuick();
    await modalAction('program-eligible-courses','Apply selections');
    await field('[data-eligibility-mode=course]','preferred','change');
    assert.match(await summary(),/Preferred, not required to apply:/);
    assert.ok((await summary()).includes(quickChoice));
    const schools=await client.evaluate("document.querySelector('[data-checkbox-picker=program-school-types]').innerText");
    await openModal('program-school-types');
    await client.evaluate("document.querySelector('[data-eligibility-options-for=program-school-types] input[type=checkbox]').click()");
    assert.equal(await client.evaluate("document.querySelector('[data-checkbox-picker=program-school-types]').innerText"),schools);
    await modalAction('program-school-types','Cancel');
    assert.equal(await client.evaluate("document.querySelector('[data-checkbox-picker=program-school-types]').innerText"),schools);
    await openModal('program-eligible-years');
    await client.send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await client.send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await wait(()=>client.evaluate("!document.querySelector('[data-eligibility-options-for=program-eligible-years]')"),'Escape closes year picker');
    assert.equal(await client.evaluate('document.activeElement.id'),'program-eligible-years');
    const locations=await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-locations]').innerText");
    await openModal('program-eligible-locations');
    assert.equal(await client.evaluate("!!document.querySelector('[data-eligibility-options-for=program-eligible-locations] #program-coverage-region')"),true);
    await client.evaluate("document.querySelector('[data-eligibility-options-for=program-eligible-locations] [data-open-rule]').click()");
    assert.equal(await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-locations]').innerText"),locations);
    await modalAction('program-eligible-locations','Cancel');
    assert.equal(await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-locations]').innerText"),locations);
    const policyText=await client.evaluate("document.querySelector('[data-education-policy-editor]').innerText");
    await openModal('program-education-policy');
    await client.evaluate("document.querySelector('[data-eligibility-options-for=program-education-policy] input[type=checkbox]').click()");
    await client.evaluate("document.querySelector('[data-eligibility-options-for=program-education-policy] input[value=Enrolled]').click()");
    assert.equal(await client.evaluate("(()=>{const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);return ids.length===new Set(ids).size})()"),true);
    assert.equal(await client.evaluate("document.querySelector('[data-eligibility-options-for=program-education-policy] footer').getBoundingClientRect().bottom<=innerHeight+1"),true);
    await modalAction('program-education-policy','Cancel');
    assert.equal(await client.evaluate("document.querySelector('[data-education-policy-editor]').innerText"),policyText);
    await openSummary();
    await click('Write custom summary');
    await field('#program-eligibility-summary','Applicants must meet the configured study and coverage rules.');
    await modalAction('program-summary-editor','Apply changes');
    await field('#program-academic-rule','percentage','change');await field('#program-academic-minimum','86');
    assert.equal(await summary(),'Applicants must meet the configured study and coverage rules.');
    if(!(await checkbox('College / University'))) await toggleEducation('College / University');
    await toggleEducation('Senior High School');
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Change education levels?')"),'education-change confirmation');
    await click('Keep current settings',"document.querySelector('[role=alertdialog]')");
    assert.equal(await checkbox('Senior High School'),true);
    await toggleEducation('Senior High School');
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Change education levels?')"),'second education-change confirmation');
    await click('Change education levels',"document.querySelector('[role=alertdialog]')");
    assert.equal(await checkbox('Senior High School'),false);
    assert.equal(await client.evaluate("document.querySelector('[data-eligibility-picker=program-eligible-courses]').innerText.includes('STEM')"),false);
    await openModal('program-eligible-courses');
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Choose replacement values')"),'explicit choice warning inside picker');
    await click('Got it',"document.querySelector('[role=alertdialog]')");
    await wait(()=>client.evaluate("document.querySelector('[data-eligibility-options-for=program-eligible-courses]')?.contains(document.activeElement)"),'focus returns to options after nested warning');
    await modalAction('program-eligible-courses','Cancel');
    await client.evaluate("document.querySelector('.program-builder-form').requestSubmit()");
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Resolve the education-level changes')"),'replacement-choice save guard');
    await click('OK',"document.querySelector('[role=alertdialog]')");
    const after=await get('/provider/scholarships/'+before.id);assert.equal(after.status,200);
    assert.deepEqual(persisted(after.body.scholarship),persisted(before));
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;console.log('PASS Modal options, draft Apply/Cancel, stable background, keyboard focus, school/location/qualification cancellation, custom summary, study-level confirmation and replacement guard. No program records saved.');
`+source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}catch(error){console.error(error.message);process.exitCode=1;}
