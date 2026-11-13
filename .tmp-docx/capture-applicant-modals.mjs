import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/applicant-modals-20261114';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
    .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/capture.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
source = source.slice(0, start) + String.raw`
    report.skipped = [];
    async function capture(name, action, selector) {
        try {
            await client.evaluate(action);
            const until = Date.now() + 4500;
            while (!await client.evaluate('!!document.querySelector(' + JSON.stringify(selector) + ')')) {
                if (Date.now() > until) throw new Error('Not available in this account');
                await delay(100);
            }
            await delay(250);
            await shot(name);
            console.log('CAPTURED ' + name);
            return true;
        } catch (error) { report.skipped.push({ name, reason: error.message }); return false; }
    }
    const press = text => "(()=>{const b=[...document.querySelectorAll('button')].filter(b=>b.offsetParent!==null&&b.innerText.trim().includes("+JSON.stringify(text)+")).at(-1);if(!b)throw new Error('Button unavailable');b.click()})()";
    const goProfile = () => navigate('/dashboard/profile', '!!document.querySelector("[data-profile-details]")');
    const dismiss = async () => {
        await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape' });
        await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape' });
        await delay(100);
    };
    if (process.env.CAPTURE_SUPPLEMENT) {
        await login('cohort.learner.apply');
        await goProfile();
        await capture('privacy-notice', press('Who can access this?'), '[role="dialog"][aria-label]');
        await goProfile();
        await capture('profile-supporting-evidence', press('Supporting evidence'), '[data-profile-evidence-list]');
        await capture('document-upload-terms', press('Read terms'), '[role="dialog"][aria-label]');
        await goProfile();
        await capture('profile-personal', 'document.querySelector("[data-profile-section=personal] button").click()', '[aria-labelledby="profile-editor-title"]');
        await client.evaluate("document.querySelector('[data-photo-controls-toggle]')?.click()");
        await capture('profile-camera', press('Open camera'), '[aria-labelledby="profile-camera-title"]');
        await goProfile();
        await capture('profile-location', 'document.querySelector("[data-profile-section=location] button").click()', '[aria-labelledby="profile-editor-title"]');
        await capture('location-pin-picker', press('map'), '[aria-labelledby="location-map-modal-title"]');
        await goProfile();
        await capture('notifications', press('Notifications'), '[role="dialog"][aria-label="Notifications"]');
        await goProfile();
        await capture('profile-personal', 'document.querySelector("[data-profile-section=personal] button").click()', '[aria-labelledby="profile-editor-title"]');
        await client.evaluate("const el=document.querySelector('[data-profile-editor-body] input[type=text]'); el.value += 'x'; el.dispatchEvent(new Event('input',{bubbles:true}));");
        await capture('unsaved-changes-confirmation', "document.querySelector('[aria-label=\"Close profile editor\"]').click()", '[role="alertdialog"]');
        await login('cohort.process.support.review');
        await navigate('/dashboard/monitoring/31/check-ins', '!!document.querySelector("[data-monitoring-check-ins]") || document.body.innerText.includes("Check-ins")');
        await delay(400);
        const checkInHref = await client.evaluate('[...document.querySelectorAll("a")].find(a=>a.getAttribute("href")?.includes("/check-ins/check-in-"))?.getAttribute("href")');
        if (checkInHref) {
            await navigate(checkInHref, '!!document.querySelector("[data-monitoring-check-in-record]")');
            await capture('check-in-case-history', press('Case history'), '[aria-labelledby="monitoring-history-title"]');
        }
    } else {
    await login('cohort.learner.apply');
    await goProfile();
    for (const section of process.env.CAPTURE_REMAINING ? [] : ['personal', 'academic', 'household', 'location']) {
        await capture('profile-' + section, 'document.querySelector("[data-profile-section=' + section + '] button").click()', '[aria-labelledby="profile-editor-title"]');
        if (section === 'personal') {
            await client.evaluate("const input=document.querySelector('#profile-first-name');if(input){input.value=input.value+' ';input.dispatchEvent(new Event('input',{bubbles:true}))}");
        }
        await dismiss();
        await goProfile();
    }
    await capture('profile-supporting-evidence', press('Supporting evidence'), '[data-profile-evidence-list]');
    await goProfile();
    await capture('profile-map', press('View map'), '[aria-labelledby="location-map-modal-title"]');
    await goProfile();
    await capture('report-platform-problem', press('Report a problem'), '[aria-labelledby="applicant-report-title"]');
    await goProfile();
    await capture('logout-confirmation', press('Logout'), '[role="alertdialog"]');
    await goProfile();
    await capture('privacy-notice', press('Privacy'), '[data-terms-modal], [role="dialog"][aria-label]');
    await navigate('/dashboard/scholarships', '!!document.querySelector("[data-scholarship-logo]")');
    await capture('profile-match-explanation', "[...document.querySelectorAll('button')].find(b=>b.ariaLabel==='How your profile match is calculated').click()", '[data-profile-match-dialog][open]');
    await dismiss();
    await capture('compare-scholarships', press('Compare scholarships'), '[aria-labelledby="scholarship-comparison-title"]');
    await navigate('/dashboard/documents', 'document.body.innerText.includes("My Documents") && document.readyState === "complete"');
    await delay(500);
    await capture('document-preview', press('View'), '[role="dialog"][aria-label]');
    await login('cohort.learner.screening');
    await navigate('/dashboard/applications/1/history', '!!document.querySelector("[title]") && document.body.innerText.includes("Application timeline")');
    await capture('application-history-event', "[...document.querySelectorAll('button')].find(b=>b.title==='View event details').click()", '[aria-labelledby="history-event-title"]');
    await login('cohort.learner.agreement');
    await navigate('/dashboard/applications/11/outcome', '!!document.querySelector("#recipient-agreement")');
    await capture('recipient-agreement', press('Review and respond'), '[aria-labelledby="recipient-agreement-title"]');
    await login('cohort.learner.release.confirm');
    await navigate('/dashboard/monitoring/18/benefits', '!!document.querySelector("[data-monitoring-benefits]") || document.body.innerText.includes("Confirm receipt")');
    const links = await client.evaluate('[...document.querySelectorAll("a")].filter(a=>a.getAttribute("href")?.includes("/benefits/")).map(a=>a.getAttribute("href"))');
    if (links.length) await navigate(links[0], 'document.body.innerText.includes("Confirm receipt")');
    await capture('confirm-benefit-receipt', press('Confirm receipt'), '[aria-labelledby="benefit-response-title"]');
    await dismiss();
    await capture('report-benefit-problem', press('Report a problem'), '[data-delivery-dialog]');
    await login('cohort.learner.support.decision');
    await navigate('/dashboard/applications/16/outcome', '!!document.querySelector("#recipient-agreement")');
    await delay(500);
    await capture('scholarship-withdrawal-request', press('Request withdrawal'), '[data-withdrawal-dialog]');
    await login('cohort.process.support.history');
    await navigate('/dashboard/monitoring/34/history', '!!document.querySelector("[data-monitoring-history]") || document.body.innerText.includes("Scholarship history") || document.body.innerText.includes("Review decision")');
    const decisionHref = await client.evaluate('[...document.querySelectorAll("a")].find(a=>a.getAttribute("href")?.includes("/history/outcome-"))?.getAttribute("href")');
    if (decisionHref) {
        await navigate(decisionHref, 'document.body.innerText.includes("Respond")');
        await capture('support-decision-response', press('Respond'), '[aria-labelledby="support-outcome-response-title"]');
    }
    }
    report.passed = true;
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
