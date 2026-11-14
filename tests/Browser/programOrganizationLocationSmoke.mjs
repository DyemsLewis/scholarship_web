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
    const address = () => client.evaluate("document.querySelector('#program-location-address').value");
    await login('nuvareya.programs');
    await navigate('/provider/programs/8/edit/dates-location', "!!document.querySelector('[data-program-location]')");
    const original = (await get('/provider/scholarships/8')).body.scholarship;
    const organization = await client.evaluate('({name:window.portalUser.provider_name,address:window.portalUser.provider_address,latitude:window.portalUser.provider_latitude,longitude:window.portalUser.provider_longitude})');
    assert.ok(organization.address && organization.latitude && organization.longitude);
    assert.equal(await address(),original.location_address,'Existing program venues are not overwritten on load.');
    await field('#program-location-name','Another program venue');
    await field('#program-location-address','Different venue, Antipolo City');
    await click('Use organization location');
    await wait(()=>client.evaluate("document.querySelector('[role=alertdialog]')?.innerText.includes('Replace the program location?')"),'replace confirmation');
    await click('Keep current venue',"document.querySelector('[role=alertdialog]')");
    assert.equal(await address(),'Different venue, Antipolo City');
    await click('Use organization location');
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'replace confirmation again');
    await click('Use organization location',"document.querySelector('[role=alertdialog]')");
    assert.equal(await address(),organization.address);
    assert.equal(await client.evaluate("document.querySelector('#program-location-name').value"),organization.name);
    assert.match(await client.evaluate("document.querySelector('[data-program-location-status]').innerText"),/address and map pin copied/);
    assert.ok(await client.evaluate("document.querySelector('[data-program-location]').innerText.includes('Review map pin')"));
    const clip = await client.evaluate("(()=>{const r=document.querySelector('[data-program-location]').getBoundingClientRect();return{x:r.x-12,y:r.y+scrollY-10,width:r.width+24,height:r.height+24,scale:1}})()");
    const screenshot = await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip});
    const file = path.join(folder,'program-location-reuse.png');await writeFile(file,Buffer.from(screenshot.data,'base64'));report.screenshots.push(file);
    await client.evaluate(String.raw` + '`' + String.raw`(() => {
        const realPost=window.axios.post.bind(window.axios);
        window.axios.post=async(url,payload,...args)=>{
            if(url!=='/provider/scholarships/8')return realPost(url,payload,...args);
            window.__locationPayload=Object.fromEntries(payload.entries());
            throw {response:{data:{message:'Browser-only save inspection; nothing was saved.'}}};
        };
    })()` + '`' + String.raw`);
    await click('Save draft');
    await wait(()=>client.evaluate('!!window.__locationPayload'),'captured save payload');
    const payload=await client.evaluate('window.__locationPayload');
    assert.equal(payload.location_address,organization.address);
    assert.equal(Number(payload.latitude),Number(organization.latitude));
    assert.equal(Number(payload.longitude),Number(organization.longitude));
    assert.equal(payload.title,original.title);
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'simulated save result');
    await client.evaluate("[...document.querySelector('[role=alertdialog]').querySelectorAll('button')].at(-1).click()");
    await field('#program-location-address','A different address after copying');
    assert.match(await client.evaluate("document.querySelector('[data-program-location-status]').innerText"),/Address changed/);
    assert.ok(await client.evaluate("document.querySelector('[data-program-location]').innerText.includes('Set map pin')"));
    await click('Save draft');
    await wait(()=>client.evaluate("!!document.querySelector('[role=alertdialog]')"),'second simulated save result');
    const changed=await client.evaluate('window.__locationPayload');
    assert.equal(changed.latitude,'');assert.equal(changed.longitude,'');
    assert.equal(await client.evaluate('window.portalUser.provider_address'),organization.address);
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Organization location/pin reuse for program staff, existing venue preservation, cancel/confirm, save payload and manual-address pin reset. No records saved.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
