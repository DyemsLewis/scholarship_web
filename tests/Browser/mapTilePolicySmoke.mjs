import { readFile } from 'node:fs/promises';

// Reuse the isolated Chrome/login harness; all business writes remain blocked.
const origin = process.env.MAP_TILE_SMOKE_ORIGIN || 'https://findscholarship.online';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replace("accountability-hosted-part6';", "map-tile-policy-20261105';")
    .replaceAll('accountability-part6-hosted-browser-20261103.json', 'map-tile-policy-browser-20261105.json')
    .replace('cacheDisabled: true', 'cacheDisabled: false')
    .replace("{ patterns: [{ urlPattern: origin + '/*', requestStage: 'Request' }] }", "{ patterns: [{ urlPattern: '*', requestStage: 'Request' }] }");
source = source.replace('const route = new URL(request.url).pathname;', String.raw`
            const url = new URL(request.url);
            if (url.hostname === 'tile.openstreetmap.org' || url.hostname.endsWith('.tile.openstreetmap.org')) {
                report.tileRequests ??= [];
                report.tileRequests.push({ url: request.url, headers: request.headers });
                // A fixture prevents QA from generating requests to community tile servers.
                void client.send('Fetch.fulfillRequest', { requestId, responseCode: 200,
                    responseHeaders: [{name:'Content-Type',value:'image/png'}, {name:'Cache-Control',value:'public, max-age=86400'}],
                    body:'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==' }).catch(()=>{});
                return;
            }
            if (url.hostname === 'nominatim.openstreetmap.org') {
                void client.send('Fetch.fulfillRequest', { requestId, responseCode: 200,
                    responseHeaders: [{name:'Content-Type',value:'application/json'}, {name:'Access-Control-Allow-Origin',value:origin}],
                    body:Buffer.from(JSON.stringify({display_name:'Quezon City, Philippines',address:{city:'Quezon City',country_code:'ph'}})).toString('base64') }).catch(()=>{});
                return;
            }
            const route = url.pathname;`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    const page = await fetch(origin + '/login');
    assert.equal(page.headers.get('referrer-policy'), 'no-referrer');
    await login('learner.profile');
    await navigate('/dashboard/profile', "[...document.querySelectorAll('button')].some(e=>e.innerText.trim()==='View map')");
    await click('View map');
    await wait(()=>client.evaluate("document.querySelectorAll('.leaflet-tile').length>0"), 'profile viewer tiles');
    const verify = async () => {
        await wait(()=>Promise.resolve((report.tileRequests?.length||0)>0), 'intercepted tile request');
        assert.ok(await client.evaluate("[...document.querySelectorAll('.leaflet-tile')].every(e=>e.referrerPolicy==='strict-origin')"));
        for (const request of report.tileRequests) {
            assert.equal(new URL(request.url).hostname, 'tile.openstreetmap.org');
            const referer = Object.entries(request.headers).find(([name])=>name.toLowerCase()==='referer')?.[1];
            assert.equal(referer, origin + '/');
            assert.ok(!Object.keys(request.headers).some(name=>['cache-control','pragma'].includes(name.toLowerCase())));
        }
    };
    await verify();
    await client.evaluate("document.querySelector('[aria-label=\"Close map\"]').click()");
    await navigate('/dashboard/profile?section=location', "!!document.querySelector('[data-address-editor]')");
    await client.evaluate("document.querySelector('[data-profile-map-action]').click()");
    await wait(()=>client.evaluate("!!window.L?.Map && !!document.querySelector('.leaflet-container')"), 'picker library');
    await client.evaluate("L.Map.addInitHook(function(){window.__testMap=this});document.querySelector('[aria-label=\"Close map\"]').click()");
    await client.evaluate("document.querySelector('[data-profile-map-action]').click()");
    await wait(()=>client.evaluate("!!window.__testMap && !!document.querySelector('.leaflet-marker-icon')"), 'picker');
    await client.evaluate("void window.__testMap.fire('click',{latlng:{lat:14.5,lng:120.8}})");
    await wait(()=>client.evaluate("document.body.innerText.includes('not on water or in another country')"), 'water remains blocked');
    await client.evaluate("void window.__testMap.fire('click',{latlng:{lat:6.1,lng:116.5}})");
    await wait(()=>client.evaluate("document.body.innerText.includes('Your previous pin has been kept')"), 'foreign point remains blocked');
    await verify();
    await login('marilag.programs');
    await navigate('/provider/programs/1/edit/dates-location', "[...document.querySelectorAll('button')].some(e=>/map pin/i.test(e.innerText))");
    await client.evaluate("[...document.querySelectorAll('button')].find(e=>/map pin/i.test(e.innerText)).click()");
    await wait(()=>client.evaluate("document.querySelectorAll('.leaflet-tile').length>0"), 'provider map');
    await verify();
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []);
    report.passed=true; report.realTileRequests=0;
    console.log('PASS Origin-only real browser headers for applicant viewer, picker, and provider maps; no private URLs or cache bypass. Tile responses mocked; no business writes.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
