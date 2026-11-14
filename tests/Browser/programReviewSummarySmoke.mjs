import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const targetOrigin = process.env.PROGRAM_BUILDER_ORIGIN || 'http://127.0.0.1:8000';
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-final-review-20261114' + (targetOrigin.startsWith('https://') ? '-hosted' : '');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(targetOrigin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const sectionText = id => client.evaluate("document.querySelector('[data-review-section='+"+JSON.stringify(id)+"+']').innerText");
    const facts = id => client.evaluate("Object.fromEntries([...document.querySelector('[data-review-section='+"+JSON.stringify(id)+"+'] dl').children].map(e=>[e.querySelector('dt').innerText,e.querySelector('dd').innerText]))");
    const edit = id => client.evaluate("document.querySelector('[data-review-section='+"+JSON.stringify(id)+"+'] button').click()");
    const review = async() => {
        await client.evaluate("[...document.querySelectorAll('.program-builder-step')].at(-1).click()");
        await wait(()=>client.evaluate("!!document.querySelector('[data-program-review]')"),'review step');
    };
    const field = (selector,value) => client.evaluate("(()=>{const e=document.querySelector("+JSON.stringify(selector)+");e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    const capture = async(name,first,last) => {
        const clip = await client.evaluate("(()=>{const a=document.querySelector('[data-review-section='+"+JSON.stringify(first)+"+']').getBoundingClientRect(),b=document.querySelector('[data-review-section='+"+JSON.stringify(last)+"+']').getBoundingClientRect();return{x:a.x-8,y:a.y+scrollY-8,width:a.width+16,height:b.bottom-a.top+16,scale:1}})()");
        const screenshot=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});
        const file=path.join(folder,name+'.png');await writeFile(file,Buffer.from(screenshot.data,'base64'));report.screenshots.push(file);
    };
    await login('nuvareya.programs');
    await navigate('/provider/programs/create', "!!document.querySelector('.program-builder-form')");
    assert.equal(await client.evaluate("document.querySelector('[data-program-save-status]').innerText.trim()"),'Not saved yet');
    assert.ok(await client.evaluate("[...document.querySelectorAll('button')].some(b=>b.innerText.trim()==='Save draft')"));
    await navigate('/provider/programs/8/edit/review', "document.querySelectorAll('[data-review-section]').length===6");
    const original=(await get('/provider/scholarships/8')).body.scholarship;
    assert.equal((await facts('basics'))['Program title'],original.title);
    assert.equal((await facts('support'))['Recipient slots'],Number(original.slots_available).toLocaleString('en-PH'));
    assert.ok((await sectionText('support')).includes(original.benefits[0].title));
    assert.ok((await sectionText('dates-location')).includes(original.location_address));
    assert.match(await sectionText('selection'),/Fixed intake/);
    assert.equal(await client.evaluate("[...document.querySelectorAll('[data-review-details]')].every(e=>!e.open)"),true);
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),true);
    await capture('program-final-review','basics','support');
    await capture('program-review-application-selection','application','selection');
    await client.evaluate("document.querySelector('[data-review-details=application] summary').click()");
    assert.equal(await client.evaluate("document.querySelector('[data-review-details=application]').open"),true);
    assert.ok((await sectionText('application')).includes(original.handoff_instructions));
    for(const documentName of original.post_qualification_requirements.split(/\r?\n/).filter(Boolean))assert.ok((await sectionText('application')).includes(documentName));

    await edit('basics');
    await wait(()=>client.evaluate("!!document.querySelector('input[maxlength]')"),'edit Basics');
    await field('input[maxlength]','Unsaved review title');
    await review();
    assert.equal((await facts('basics'))['Program title'],'Unsaved review title');
    for(const [id,path] of [['support','support'],['dates-location','dates-location'],['eligibility','eligibility'],['application','application'],['selection','selection']]){
        await edit(id);
        await wait(()=>client.evaluate('location.pathname.endsWith('+JSON.stringify('/'+path)+')'),'edit '+id);
        if(id==='dates-location')assert.ok(await client.evaluate("document.querySelector('[data-program-location]').innerText.includes('Use organization location')"));
        if(id==='eligibility'){
            assert.equal(await client.evaluate("document.getElementById('program-eligible-courses').tagName"),'BUTTON');
            assert.equal(await client.evaluate("document.querySelectorAll('[data-eligibility-picker] [data-add-option]').length"),0);
            assert.ok(await client.evaluate("!!document.getElementById('program-eligibility-summary')"));
        }
        if(id==='selection')assert.equal(await client.evaluate("document.querySelector('[data-scoring-state]').innerText"),'Fixed for this intake');
        await review();
    }
    await edit('basics');
    await wait(()=>client.evaluate("!!document.querySelector('input[maxlength]')"),'edit blank title');
    await field('input[maxlength]','');await review();
    assert.equal((await facts('basics'))['Program title'],'Not set');
    assert.match(await sectionText('basics'),/Needs attention/);
    await click('Submit for review');
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'existing incomplete-section guard');
    assert.match(await client.evaluate("document.querySelector('[role=alertdialog]').innerText"),/Program is not ready/);
    assert.ok(await client.evaluate("location.pathname.endsWith('/basics')"));
    await client.evaluate("[...document.querySelector('[role=alertdialog]').querySelectorAll('button')].at(-1).click()");
    assert.equal((await get('/provider/scholarships/8')).body.scholarship.title,original.title);

    await login('nuvareya.manager');
    await navigate('/provider/programs/8/edit/review', "document.querySelectorAll('[data-review-section]').length===6");
    assert.equal((await facts('basics'))['Program title'],original.title);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Actual details, collapsed long content, document lists, all six edit actions, live unsaved values, unchanged submission guard and coordinator/manager access. No program records saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
