import { readFile } from 'node:fs/promises';

let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/application-card-actions-hosted-20261114';
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    await login('learner.aurora');
    await navigate('/dashboard/applications',"!!document.querySelector('[data-application-card-actions] [data-application-support-link]')");
    report.layouts=[];
    for(const width of [1440,1024,768,390]){
        await client.send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<640});
        await delay(100);
        const audit=await client.evaluate("(()=>{const groups=[...document.querySelectorAll('[data-application-card-actions]')].filter(g=>g.children.length===2);return {overflow:document.documentElement.scrollWidth>innerWidth+2,groups:groups.map(g=>({buttons:[...g.querySelectorAll('a')].map(a=>{const r=a.getBoundingClientRect();return {width:r.width,height:r.height,x:r.x,y:r.y,clipped:a.scrollWidth>a.clientWidth+1,href:a.getAttribute('href')}})}))}})()");
        assert.ok(audit.groups.length>0);
        assert.equal(audit.overflow,false);
        for(const group of audit.groups){
            const [first,second]=group.buttons;
            assert.equal(first.height,44);assert.equal(second.height,44);
            assert.ok(Math.abs(first.width-second.width)<1);
            assert.equal(first.clipped,false);assert.equal(second.clipped,false);
            assert.ok(first.href);assert.ok(second.href);
            if(width>=640){assert.ok(Math.abs(first.y-second.y)<1);assert.ok(second.x>=first.x+first.width);}
            else{assert.ok(Math.abs(first.x-second.x)<1);assert.ok(second.y>=first.y+first.height);}
        }
        report.layouts.push({width,...audit});
        if(width===1440 || width===390){
            const bounds=await client.evaluate("(()=>{const cards=[...document.querySelectorAll('article')].filter(a=>a.querySelector('[data-application-support-link]'));const card=cards.find(a=>a.innerText.includes('School Essentials'))||cards[0];card.scrollIntoView({block:'center'});const r=card.getBoundingClientRect();return {x:r.left-4,y:r.top+scrollY-4,width:r.width+8,height:r.height+8,scale:1}})()");
            const {data}=await client.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:bounds});
            const file=path.join(folder,'application-actions-'+width+'.png');
            await writeFile(file,Buffer.from(data,'base64'));report.screenshots.push(file);
        }
    }
    assert.deepEqual(report.errors,[]);assert.deepEqual(report.serverErrors,[]);assert.deepEqual(report.blockedWrites,[]);
    report.passed=true;
    console.log('PASS Equal application/support buttons, desktop/tablet alignment, mobile stacking and no clipped labels. No business changes submitted.');
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch(error){console.error(error.message);process.exitCode=1;}
