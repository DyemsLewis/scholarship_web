import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/single-provider-guide-20261114';
const captures = [
    ['learner.aurora','/dashboard/profile','applicant-profile','Applicant Aurora'],
    ['learner.aurora','/dashboard/scholarships','applicant-scholarships','Orivanta'],
    ['learner.aurora','/dashboard/documents','applicant-documents','Documents'],
    ['learner.aurora','/dashboard/applications/1','applicant-application','Orivanta Campus'],
    ['learner.aurora','/dashboard/applications/14/outcome','applicant-agreement','Orivanta Continuing'],
    ['learner.brio','/dashboard/applications/2/activities','applicant-formal','Orivanta Campus'],
    ['learner.brio','/dashboard/applications/8/activities','applicant-interview','Orivanta Leadership'],
    ['learner.brio','/dashboard/monitoring/19/history','applicant-history','Orivanta Term'],
    ['learner.brio','/dashboard/monitoring/23/benefits/release-5','applicant-upcoming-release','Orivanta School'],
    ['learner.cielo','/dashboard/applications/5/activities','applicant-exam','Orivanta Applied'],
    ['learner.cielo','/dashboard/monitoring/20/check-ins','applicant-check-in','Orivanta Term'],
    ['learner.cielo','/dashboard/monitoring/24/benefits/release-6','applicant-receipt','Orivanta School'],
    ['learner.dune','/dashboard/monitoring/21/benefits/release-4','applicant-payout','Orivanta Term'],
    ['orivanta.profile','/provider/workspaces/organization-profile/public','provider-profile','Public profile'],
    ['orivanta.programs','/provider/programs/9/edit/review','provider-program-review','Orivanta Digital'],
    ['orivanta.reviewer','/provider/applications/1','provider-screening','Applicant Aurora'],
    ['orivanta.selection','/provider/applications/2','provider-formal','Applicant Brio'],
    ['orivanta.selection','/provider/workspaces/selection/active','provider-sessions','Selection sessions'],
    ['orivanta.selection','/provider/applications/7','provider-interview-result','Applicant Aurora'],
    ['orivanta.selection','/provider/applications/13','provider-missing-score','Applicant Dune'],
    ['orivanta.decisions','/provider/workspaces/decisions/pending?program_id=5','decision-comparison','Applicant Aurora'],
    ['orivanta.decisions','/provider/applications/10','decision-record','Applicant Aurora'],
    ['orivanta.monitoring','/provider/monitoring/6/plan','monitoring-plan','Monitoring plan'],
    ['orivanta.monitoring','/provider/workspaces/monitoring/check-ins/1?application_id=16','monitoring-record','Applicant Cielo'],
    ['orivanta.monitoring','/provider/workspaces/monitoring/check-ins/1?application_id=17','monitoring-ready-to-close','Applicant Dune'],
    ['orivanta.recipients','/provider/applications/19?section=support','recipient-closeout','Applicant Brio'],
    ['orivanta.recipients','/provider/applications/25?section=support','recipient-one-time-closeout','Applicant Dune'],
    ['orivanta.recipients','/provider/workspaces/recipients/concerns','recipient-concerns','Agreement concerns'],
    ['orivanta.recipients','/provider/workspaces/recipients/withdrawals','recipient-withdrawals','Withdrawal'],
    ['orivanta.releases','/provider/workspaces/releases/schedule','provider-release-schedule','Schedule'],
    ['orivanta.releases','/provider/workspaces/releases/distributions/5','provider-release-record','Applicant Brio'],
    ['orivanta.releases','/provider/workspaces/releases/distributions/4','provider-payout-review','Applicant Dune'],
    ['orivanta.releases','/provider/workspaces/releases/delivery-reports','provider-delivery-reports','Delivery reports'],
    ['orivanta.support','/provider/workspaces/support/needs-response','provider-support','Clarify'],
    ['orivanta.team','/provider/workspaces/team/active','provider-team','Active accounts'],
    ['orivanta.billing','/provider/workspaces/billing/services','provider-billing','Optional support services'],
    ['lumenops.reviews','/admin/workspaces/reviews/applicants/28','admin-applicant-review','Applicant Verification'],
    ['lumenops.reviews','/admin/workspaces/reviews/providers/9','admin-provider-review','Orivanta'],
    ['lumenops.reviews','/admin/workspaces/reviews/programs/10','admin-program-review','Orivanta College'],
    ['lumenops.reviews','/admin/workspaces/reviews/monitoring','admin-monitoring','Monitoring oversight'],
    ['lumenops.reviews','/admin/workspaces/reviews/recipient-cases','admin-case-review','Case reviews'],
    ['lumenops.support','/admin/workspaces/support/cases/2','admin-support','notification settings'],
    ['lumenops.accounts','/admin/workspaces/accounts/23','admin-account','Applicant Aurora'],
    ['lumenops.billing','/admin/workspaces/billing/requests/3','admin-billing','Application cycle support'],
    ['lumenops.finance','/admin/workspaces/finance/receipts/3','admin-finance','Application cycle support'],
    ['lumenops.records','/admin/workspaces/records/activity','admin-records','Activity'],
    ['orivanta.manager','/provider','provider-manager','Provider dashboard'],
    ['orivanta','/provider','provider-representative','Provider dashboard'],
    ['lumenops.manager','/admin','admin-manager','Operations'],
    ['lumenops.root','/admin','superadmin','Super'],
];
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs','utf8');
source = source.replace(/^const folder = .*;$/m,`const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json',folder+'/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure',start);
let previous = {captureDetails:[]};
try { previous=JSON.parse(await readFile(folder+'/browser.json','utf8')); } catch {}
source = source.slice(0,start) + `    const captures=${JSON.stringify(captures)};\n    const previous=${JSON.stringify(previous.captureDetails)};\n` + String.raw`
    report.captureDetails=previous;report.captureFailures=[];
    const only=process.env.GUIDE_CAPTURE_ONLY?.split(',');
    let account;
    for(const [username,url,name,expected] of captures){
        if(only&&!only.includes(name))continue;
        try{
            if(account!==username){await login(username);account=username;}
            await navigate(url,'document.readyState==="complete" && document.body.innerText.includes('+JSON.stringify(expected)+')');
            await wait(()=>client.evaluate("!/(?:Loading (?:the |your )?(?:record|program|profile|review|schedule)|Please wait)/i.test(document.body.innerText)"),'loaded '+name);
            await delay(600);
            const body=await client.evaluate('document.body.innerText');
            assert.ok(!/Nuvareya|Velasora|Kinoraya|novacore|cohort\.(?:learner|process)/i.test(body),'Old demo identity on screen');
            if(name==='provider-interview-result'){
                await client.evaluate('document.querySelector("input[type=radio][value=passed]").click()');
                await wait(()=>client.evaluate('!!document.querySelector("[data-stage-evaluation-fields]")'),'visible stage evaluation');
                await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.trim()==="Review outcome").closest("form").scrollIntoView({block:"center"})');
            }
            await shot(name);
            report.captureDetails=report.captureDetails.filter(item=>item.name!==name);
            report.captureDetails.push({name,account:username,url:origin+url,captured_at:new Date().toISOString()});
            console.log('CAPTURED '+name);
            if(name==='decision-comparison'){
                await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.getAttribute("aria-label")?.startsWith("View evaluation breakdown")).click()');
                await wait(()=>client.evaluate('!!document.querySelector("[data-selection-breakdown-dialog]")'),'score breakdown');
                await shot('decision-breakdown');report.captureDetails.push({name:'decision-breakdown',account:username,url:origin+url,captured_at:new Date().toISOString()});
            }
            if(name==='provider-missing-score'){
                await client.evaluate('document.querySelector("[data-recorded-evaluations]").open=true');
                await wait(()=>client.evaluate('[...document.querySelectorAll("button")].some(b=>b.innerText.trim()==="Add score")'),'Add score');
                await click('Add score');
                await wait(()=>client.evaluate('!!document.querySelector("[data-stage-evaluation-dialog]")'),'score dialog');
                await shot('selection-score-dialog');report.captureDetails.push({name:'selection-score-dialog',account:username,url:origin+url,captured_at:new Date().toISOString()});
            }
            if(name==='monitoring-record'){
                await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.includes("Current academic record")).click()');
                await wait(()=>client.evaluate('document.body.innerText.includes("Record result")'),'academic result');
                await client.evaluate('[...document.querySelectorAll("button")].find(b=>b.innerText.trim()==="Record result").closest("form").scrollIntoView({block:"center"})');
                await shot('monitoring-academic-result');report.captureDetails.push({name:'monitoring-academic-result',account:username,url:origin+url,captured_at:new Date().toISOString()});
            }
        }catch(error){report.captureFailures.push({name,account:username,url,error:error.message});console.log('FAILED '+name+': '+error.message);}
    }
    const target=await control.send('Target.createTarget',{url:'about:blank',browserContextId:(await control.send('Target.createBrowserContext')).browserContextId});
    client.socket.close();client=new Cdp('ws://127.0.0.1:'+port+'/devtools/page/'+target.targetId);await configure();
    await navigate('/register','!!document.querySelector("form")');await shot('registration');
    report.captureDetails=report.captureDetails.filter(item=>item.name!=='registration');
    report.captureDetails.push({name:'registration',account:'Not signed in',url:origin+'/register',captured_at:new Date().toISOString()});
    assert.deepEqual(report.blockedWrites,[]);assert.deepEqual(report.serverErrors,[]);
    report.passed=report.captureFailures.length===0;
    console.log('CAPTURE_PASS='+report.passed+'; captures='+report.captureDetails.length+'; failures='+report.captureFailures.length);
` + source.slice(end);
try{await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));}
catch(error){console.error(error.message);process.exitCode=1;}
