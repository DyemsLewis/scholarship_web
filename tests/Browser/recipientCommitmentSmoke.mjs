import { readFile } from 'node:fs/promises';

const origin = process.env.COMMITMENT_TEST_ORIGIN || 'https://findscholarship.online';
const username = process.env.HOSTED_AUDIT_USERNAME || 'aaaa';
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/recipient-commitment-20261115-' + (origin.startsWith('https:') ? 'hosted' : 'local');
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + `
    await login(${JSON.stringify(username)});
` + String.raw`
    const list=await get('/provider/programs/data?filter=all'); assert.equal(list.status,200);
    let program;
    for(const row of list.body.programs){
        const response=await get('/provider/programs/'+row.id+'/data'); assert.equal(response.status,200);
        if(response.body.program.actions.edit_url){program=response.body.program;break;}
    }
    assert.ok(program,'An editable program is available');
    const before=(await get('/provider/scholarships/'+program.id)).body.scholarship;
    report.agreementTypes=Object.fromEntries(Object.entries(before.recipient_agreement || {}).map(([key,value])=>[key,value===null?'null':typeof value]));
    report.programId=program.id;
    const target=program.actions.edit_url.replace(new RegExp('/basics$'),'/selection');
    await navigate(target,"!!document.querySelector('.program-builder-form') && [...document.querySelectorAll('select')].some(e=>[...e.options].some(o=>o.value==='provider_briefing'))");
    const selector="[...document.querySelectorAll('select')].find(e=>[...e.options].some(o=>o.value==='provider_briefing'))";
    report.options=[];
    for(const value of ['none','renewal','service','activities','reporting','custom','none']){
        await client.evaluate("(()=>{const e="+selector+";e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event('change',{bubbles:true}));})()");
        await delay(200);
        const state=await client.evaluate("({selected:("+selector+")?.value,form:!!document.querySelector('.program-builder-form'),text:document.body.innerText.slice(-4000)})");
        report.options.push({value,selected:state.selected,form:state.form});
        if(!state.form || report.errors.length){await shot('recipient-commitment-error');throw new Error('Commitment '+value+' failed: '+JSON.stringify({state,errors:report.errors}));}
        assert.equal(state.selected,value);
        assert.equal(state.text.includes('Recipient responsibilities'),value!=='none');
        if(value==='reporting'){
            await client.evaluate("document.querySelector('select option[value=provider_briefing]').parentElement.closest('label').scrollIntoView({block:'start'})");
            await shot('recipient-commitment-reporting');
        }
    }
    const after=(await get('/provider/scholarships/'+program.id)).body.scholarship;
    assert.deepEqual(after.recipient_agreement,before.recipient_agreement);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS All six recipient commitment options render correctly and switching back preserves the form. No program data saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error) { console.error(error.message); process.exitCode=1; }
