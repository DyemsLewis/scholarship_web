import { readFile } from 'node:fs/promises';

const origin = process.env.PWA_ORIGIN || 'http://127.0.0.1:8000';
const hosted = !origin.includes('127.0.0.1');
const accounts = (process.env.PWA_ACCOUNTS || 'cohort.learner.apply,nuvareya.manager,novacore.reviews').split(',');
const label = hosted ? 'hosted' : 'local';
let source = await readFile(new URL('./recipientAccountabilityHostedSmoke.mjs', import.meta.url), 'utf8');
source = source.replace("const origin = 'https://findscholarship.online';", `const origin = ${JSON.stringify(origin)};`)
    .replaceAll('accountability-hosted-part6', `portal-pwa-${label}-20261116`)
    .replace('url: origin + url', 'url: new URL(url, origin).href')
    .replace('cacheDisabled: true', 'cacheDisabled: false')
    .replace("if (contextId) await control.send('Target.disposeBrowserContext', { browserContextId: contextId });", "if (contextId) await control.send('Target.closeTarget', { targetId: contextId });")
    .replace("contextId = (await control.send('Target.createBrowserContext')).browserContextId;", '')
    .replace("const target = await control.send('Target.createTarget', { url: 'about:blank', browserContextId: contextId });", "const target = await control.send('Target.createTarget', { url: 'about:blank' });contextId=target.targetId;")
    .replace('await configure();', "await configure();await client.send('Storage.clearDataForOrigin',{origin,storageTypes:'all'});")
    .replace("['/login', '/hcdn-cgi/jschallenge-validate']", "['/login', '/logout', '/hcdn-cgi/jschallenge-validate']")
    .replace('storage/deployment/accountability-part6-hosted-browser-20261103.json', `storage/deployment/pwa-${label}-browser-20261116.json`);
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure');
if (start < 0 || end < start) throw new Error('Browser harness boundaries changed.');
const cases = String.raw`
    const response = await fetch(origin + '/login');
    assert.equal(response.status, 200);assert.match(response.headers.get('cache-control'), /no-store/);
    const head=await response.text();assert.match(head,/rel="manifest"/);
    const manifest=await fetch(origin+'/manifest.webmanifest');assert.equal(manifest.status,200);
    assert.match(manifest.headers.get('content-type'),/application\/(manifest\+json|octet-stream)/);
    assert.equal((await manifest.json()).display,'standalone');
    const worker=await fetch(origin+'/service-worker.js');assert.equal(worker.status,200);
    if(__HOSTED__){assert.match(worker.headers.get('cache-control'),/no-store/);assert.ok(response.headers.get('strict-transport-security'));assert.match(manifest.headers.get('content-type'),/application\/manifest\+json/);}
    const cached=()=>client.evaluate("(async()=>{const result={};for(const key of await caches.keys()){const cache=await caches.open(key);result[key]=(await cache.keys()).map(request=>new URL(request.url).pathname)}return result})()");
    const onlyNotice=async()=>{const entries=await cached();assert.equal(Object.keys(entries).length,1);assert.ok(Object.keys(entries)[0].startsWith('findscholarship-offline:'));assert.deepEqual(Object.values(entries),[['/offline.html']]);return entries;};
    let workerClient;
    const offline=async(value)=>{
        if(value)await client.send('Fetch.disable');
        const conditions={offline:value,latency:0,downloadThroughput:value?0:-1,uploadThroughput:value?0:-1};
        await workerClient?.send('Network.emulateNetworkConditions',conditions);
        await client.send('Network.emulateNetworkConditions',conditions);
        if(!value)await client.send('Fetch.enable',{patterns:[{urlPattern:origin+'/*',requestStage:'Request'}]});
    };
    const notice="!!document.querySelector('[data-offline-notice]')";
    const offlineNavigate=async(url)=>{
        await client.evaluate("(()=>{const a=document.createElement('a');a.id='pwa-offline-test';a.href="+JSON.stringify(url)+";document.body.append(a)})()");
        await offline(true);await client.evaluate("document.querySelector('#pwa-offline-test').click()");
        await wait(()=>client.evaluate(notice),'offline notice');
    };
    for(const [index,account] of __ACCOUNTS__.entries()){
        await login(account);
        await wait(()=>client.evaluate("!!navigator.serviceWorker.controller && navigator.serviceWorker.controller.scriptURL.endsWith('/service-worker.js')"),'active service worker');
        const current=await client.evaluate('location.href');
        const appManifest=await client.send('Page.getAppManifest');assert.deepEqual(appManifest.errors,[]);assert.ok(appManifest.data);
        const install=await client.send('Page.getInstallabilityErrors');assert.deepEqual(install.installabilityErrors,[]);report.installability=install;
        console.log('INSTALLABLE '+account);
        await onlyNotice();
        if(index===0){
            const targets=await fetch('http://127.0.0.1:'+port+'/json/list').then(response=>response.json());
            const workerTarget=targets.find(target=>target.type==='service_worker'&&target.url===origin+'/service-worker.js');
            assert.ok(workerTarget,'The installed worker has a running target');
            workerClient=new Cdp(workerTarget.webSocketDebuggerUrl);await workerClient.send('Runtime.enable');await workerClient.send('Network.enable');
            workerClient.on('Runtime.exceptionThrown',({exceptionDetails})=>{const message=exceptionDetails.exception?.description||exceptionDetails.text;report.errors.push(message);console.log('WORKER_ERROR '+message);});
            console.log('OFFLINE_START');
            await offlineNavigate(current);console.log('OFFLINE_NOTICE_PASS');
            assert.equal(await client.evaluate("document.body.innerText.includes('Reconnect to continue')"),true);
            assert.equal(await client.evaluate("!!window.portalUser || !!document.querySelector('meta[name=csrf-token]')"),false);
            await shot('connection-lost-desktop');
            await client.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
            assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),true);await shot('connection-lost-mobile');
            const api=await client.evaluate("fetch('/dashboard/profile/data',{cache:'no-store'}).then(()=>false).catch(()=>true)");assert.equal(api,true,'Offline APIs do not return cached records or HTML');
            await onlyNotice();await offline(false);
            await client.evaluate("document.querySelector('a').click()");
            await wait(()=>client.evaluate("!document.querySelector('[data-offline-notice]') && document.readyState==='complete' && !!window.axios && location.pathname!='/login'"),'reconnect to authenticated portal');
            await client.send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        }
        await onlyNotice();
        const logout=await client.evaluate("window.axios.post('/logout',{}, {portalToast:false}).then(response=>response.status)");assert.equal(logout,200);
        await navigate(current,"location.pathname==='/login' && !!document.querySelector('#login')");
        await onlyNotice();
        if(index===0){await offlineNavigate(current);assert.equal(await client.evaluate('!!window.portalUser'),false);await offline(false);workerClient.socket.close();workerClient=undefined;}
        console.log('PWA_PRIVACY_PASS '+account);
    }
    report.offlineCache=await onlyNotice();assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);report.passed=true;
    console.log('PASS PWA installability, public assets, offline/reconnect, private-cache isolation, and sign-in/sign-out across applicant, provider and admin.');
`.replaceAll('__HOSTED__', JSON.stringify(hosted)).replace('__ACCOUNTS__', JSON.stringify(accounts));
source = source.slice(0, start) + cases + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message);process.exitCode = 1; }
