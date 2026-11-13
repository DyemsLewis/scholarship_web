import { readFile } from 'node:fs/promises';

const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/fresh-demo-20261113/document-extra';
let source = await readFile('tests/Browser/recipientAccountabilityHostedSmoke.mjs', 'utf8');
source = source.replace(/^const folder = .*;$/m, `const folder = ${JSON.stringify(folder)};`)
  .replaceAll('storage/deployment/accountability-part6-hosted-browser-20261103.json', folder + '/browser.json');
const start = source.indexOf("    const response = await fetch(origin + '/login');");
const end = source.indexOf('} catch (error) { report.failure', start);
if (start < 0 || end < 0) throw new Error('Read-only harness anchors changed');
source = source.slice(0, start) + String.raw`
    const captures = [
      ['nuvareya.profile', '/provider/workspaces/organization-profile/public', 'Public profile', 'provider-profile'],
      ['nuvareya.programs', '/provider/programs/drafts', 'Drafts', 'provider-programs'],
      ['nuvareya.reviewer', '/provider/applications/1', 'Learner Profile 04', 'provider-screening'],
      ['nuvareya.selection', '/provider/workspaces/selection/active', 'Selection sessions', 'provider-sessions'],
      ['nuvareya.recipients', '/provider/applications/16?section=support', 'Learner Profile 19', 'provider-recipient-concerns'],
      ['nuvareya.releases', '/provider/workspaces/releases/record', 'Record distribution', 'provider-release-record'],
      ['nuvareya.releases', '/provider/workspaces/releases/upcoming', 'Upcoming releases', 'provider-release-upcoming'],
      ['nuvareya.releases', '/provider/workspaces/releases/delivery-reports', 'Delivery reports', 'provider-delivery-reports'],
      ['nuvareya.team', '/provider/workspaces/team/active', 'Active accounts', 'provider-team'],
      ['nuvareya.support', '/provider/workspaces/support/needs-response', 'Needs response', 'provider-support'],
      ['nuvareya.billing', '/provider/workspaces/billing/services', 'Optional support services', 'provider-billing'],
      ['nuvareya.manager', '/provider', 'Provider dashboard', 'provider-manager'],
      ['nuvareya', '/provider', 'Provider dashboard', 'provider-representative'],
      ['novacore.reviews', '/admin/workspaces/reviews/applicants/39', 'Learner Profile 02', 'admin-applicant-verification'],
      ['novacore.reviews', '/admin/workspaces/reviews/providers', 'Provider verification', 'admin-provider-verification'],
      ['novacore.reviews', '/admin/workspaces/reviews/programs/9', 'Nuvareya Digital Horizons Award', 'admin-program-review'],
      ['novacore.reviews', '/admin/workspaces/reviews/recipient-cases', 'Case reviews', 'admin-independent-cases'],
      ['novacore.accounts', '/admin/workspaces/accounts/attention', 'Needs attention', 'admin-accounts'],
      ['novacore.support', '/admin/workspaces/support/needs-response', 'Needs response', 'admin-support'],
      ['novacore.billing', '/admin/workspaces/billing/queues/active', 'Services in progress', 'admin-billing'],
      ['novacore.finance', '/admin/workspaces/finance/payments', 'Payments', 'admin-finance'],
      ['novacore.records', '/admin/workspaces/records/activity', 'Activity', 'admin-records'],
      ['novacore.manager', '/admin', 'Operations', 'admin-manager'],
      ['cohort.learner.profile', '/dashboard/profile', 'Learner Profile 01', 'applicant-profile-start'],
      ['cohort.learner.formal', '/dashboard/applications/2', 'Classroom Bridge', 'applicant-formal'],
      ['cohort.learner.exam.confirm', '/dashboard/applications/4', 'Applied Science', 'applicant-exam'],
      ['cohort.learner.interview.confirm', '/dashboard/applications/8', 'Community Leadership', 'applicant-interview'],
      ['cohort.learner.agreement', '/dashboard/applications/11/outcome', 'Continuing Studies', 'applicant-agreement'],
      ['cohort.process.support.history', '/dashboard/monitoring/34/history', 'Support history', 'applicant-support-history'],
      ['cohort.process.award.followup', '/dashboard/monitoring/37/benefits', 'Study Essentials', 'applicant-upcoming-award'],
    ];
    report.captureDetails = []; report.captureFailures = [];
    let currentAccount;
    for (const [account, url, expected, name] of captures) {
      try {
        if (currentAccount !== account) { await login(account); currentAccount = account; }
        await navigate(url, 'document.readyState === "complete" && document.body.innerText.includes(' + JSON.stringify(expected) + ')');
        await delay(700);
        const content = await client.evaluate('document.body.innerText');
        assert.ok(!/Talunaw|Sidlakbay|Hirayalon|demo\.student|demo\.process|demoops/.test(content), 'old demo identity');
        await shot(name);
        report.captureDetails.push({ name, account, url: origin + url, captured_at: new Date().toISOString() });
        console.log('CAPTURED ' + name);
      } catch (error) { report.captureFailures.push({ name, account, url, error: error.message }); console.log('SKIP ' + name + ': ' + error.message); }
    }
    assert.deepEqual(report.blockedWrites, []); assert.deepEqual(report.serverErrors, []);
    report.passed = report.captureFailures.length === 0;
` + source.slice(end);
try { await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64')); }
catch (error) { console.error(error.message); process.exitCode = 1; }
