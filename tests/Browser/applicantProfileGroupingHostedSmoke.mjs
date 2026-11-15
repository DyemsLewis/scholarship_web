import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/profile-grouping-hosted-20261114';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    await login('learner.aurora');
    const payload=await get('/dashboard/profile/data');assert.equal(payload.status,200);
    const guardianRequired=payload.body.user.age<=18;
    assert.equal(payload.body.profile_readiness.requires_guardian,guardianRequired);
    const guardianProof=payload.body.user.profile_evidence_requirements.find(item=>item.value==='guardian_record');
    assert.equal(guardianProof.required,guardianRequired);
    report.guardianPolicy={age:payload.body.user.age,required:guardianRequired};
    await navigate('/dashboard/profile',"document.querySelectorAll('[data-profile-details] > section').length===3 && !!document.querySelector('[data-profile-verification]')");
    const details=await client.evaluate("({headings:[...document.querySelectorAll('[data-profile-details] > section > header h3')].map(e=>e.innerText),proof:document.querySelector('[data-profile-verification] h2').innerText,shortcut:!![...document.querySelectorAll('main a')].find(e=>['Prepared documents','Eligible scholarships'].includes(e.innerText.trim()))})");
    assert.deepEqual(details.headings,['Personal details','Education','Household and residence']);
    assert.equal(details.proof,'Documents and verification');assert.equal(details.shortcut,false);
    report.sections=details.headings.concat(details.proof);
    await shot('hosted-profile-desktop');
    await client.evaluate("document.querySelector('[data-profile-section=household] > header button').click()");
    await wait(()=>client.evaluate("!!document.querySelector('[data-household-editor]')"),'household modal');
    assert.equal(await client.evaluate("document.querySelector('#profile-guardian').required"),guardianRequired);
    assert.equal(await client.evaluate("document.querySelector('#profile-guardian-email').required"),guardianRequired);
    await shot('hosted-household-guardian-rule');
    await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]').click()");
    await wait(()=>client.evaluate("!document.querySelector('#profile-editor-title')"),'close household');
    await client.evaluate("document.querySelector('[data-profile-group=location] button').click()");
    await wait(()=>client.evaluate("document.querySelector('#profile-editor-title')?.innerText==='Home address'"),'address modal');
    await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]').click()");
    await wait(()=>client.evaluate("!document.querySelector('#profile-editor-title')"),'close address');
    await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'),true);
    await client.evaluate("document.querySelector('[data-profile-section=household]').scrollIntoView({block:'start'})");
    await shot('hosted-household-mobile');
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Hosted profile grouping, age-based contact and guardian-proof requirements, edit routing and mobile layout. No profile changes saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
