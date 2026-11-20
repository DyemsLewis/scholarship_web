import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/program-organization-location-20261114';
source = source.replace("const origin = 'https://findscholarship.online';", "const origin = 'http://127.0.0.1:8000';")
    .replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const field = (selector,value) => client.evaluate("(()=>{const e=document.querySelector("+JSON.stringify(selector)+");e.value="+JSON.stringify(value)+";e.dispatchEvent(new Event('input',{bubbles:true}));})()");
    const location = "document.querySelector('#program-location-editor-options')";
    const contact = "document.querySelector('#program-public-contact-options')";
    const summary = () => client.evaluate("document.querySelector('[data-program-location]').innerText");
    await login('nuvareya.programs');
    await navigate('/provider/programs/8/edit/dates-location', "!!document.querySelector('[data-program-location]')");
    const original = (await get('/provider/scholarships/8')).body.scholarship;
    const organization = await client.evaluate('({name:window.portalUser.provider_name,address:window.portalUser.provider_address,latitude:window.portalUser.provider_latitude,longitude:window.portalUser.provider_longitude})');
    assert.ok(organization.address && organization.latitude && organization.longitude);
    const before = await summary();
    assert.ok(before.includes(original.location_address),'Existing program venues are not overwritten on load.');
    await click('Edit location');
    await wait(()=>client.evaluate("!!" + location),'location dialog');
    await field('#program-location-editor-options input[placeholder*="Room"]','Another program venue');
    await click('Use organization location',location);
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Replace the program location?')"),'replace confirmation');
    await click('Keep current venue',"document.querySelector('[role=alertdialog]')");
    assert.equal(await client.evaluate(location + ".querySelector('input[placeholder*=Room]').value"), 'Another program venue');
    await click('Use organization location',location);
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'replace confirmation again');
    await click('Use organization location',"document.querySelector('[role=alertdialog]')");
    assert.equal(await summary(),before);
    assert.ok((await client.evaluate(location + ".querySelector('[data-legacy-venue]').innerText")).includes(organization.address));
    await click('Review map pin',location);
    await wait(()=>client.evaluate("!!" + location + ".querySelector('[data-inline-location-picker] [role=region]')"),'inline map');
    assert.equal(await client.evaluate("document.querySelectorAll('[role=dialog]').length"),1);
    assert.equal(await client.evaluate(location + ".querySelector('button[type=submit]').disabled"),true);
    await shot('program-location-inline-map');
    await client.evaluate(location + ".querySelector('[aria-label=\"Close map\"]').click()");
    await click('Apply changes',location);
    assert.ok((await summary()).includes(organization.address));
    assert.match(await summary(),/Map pin selected/);

    const contactSummary = await client.evaluate("document.querySelector('[data-program-public-contact]').innerText");
    await click('Edit contact');
    await wait(()=>client.evaluate("!!" + contact),'public contact dialog');
    await field('#program-public-contact-options input[type=email]','discard@example.test');
    await click('Cancel',contact);
    assert.equal(await client.evaluate("document.querySelector('[data-program-public-contact]').innerText"),contactSummary);
    const after = (await get('/provider/scholarships/8')).body.scholarship;
    assert.equal(after.location_address,original.location_address);
    assert.equal(after.contact_email,original.contact_email);
    assert.equal(await client.evaluate('window.portalUser.provider_address'),organization.address);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Program location and public contact modals, organization copy, inline map and draft-only updates. No records saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
