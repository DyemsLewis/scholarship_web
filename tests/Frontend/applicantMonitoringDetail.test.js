import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/pages/UserMonitoringPage.vue',
    '../../resources/js/pages/UserMonitoringDetailPage.vue',
    '../../resources/js/components/monitoring-detail/ApplicantMonitoringShell.vue',
    '../../resources/js/components/monitoring-detail/MonitoringDecisionBanner.vue',
    '../../resources/js/components/monitoring-detail/MonitoringOverviewRecord.vue',
    '../../resources/js/components/monitoring-detail/SupportCloseoutSummary.vue',
    '../../resources/js/components/monitoring-detail/SupportCloseoutBenefits.vue',
    '../../resources/js/components/monitoring-detail/MonitoringCheckIns.vue',
    '../../resources/js/components/monitoring-detail/MonitoringCheckInRecord.vue',
    '../../resources/js/components/monitoring-detail/MonitoringAdjustmentModal.vue',
    '../../resources/js/components/monitoring-detail/MonitoringBenefits.vue',
    '../../resources/js/components/monitoring-detail/BenefitReleaseRecord.vue',
    '../../resources/js/components/monitoring-detail/BenefitPayoutDetailsModal.vue',
    '../../resources/js/components/monitoring-detail/BenefitReceiptResponseModal.vue',
    '../../resources/js/components/monitoring-detail/MonitoringHistory.vue',
    '../../resources/js/components/monitoring-detail/SupportOutcomeRecord.vue',
    '../../resources/js/components/monitoring-detail/SupportOutcomeResponseModal.vue',
];

for (const relativePath of components) {
    const component = relativePath.split('/').at(-1);

    test(`${component} compiles`, () => {
        const filename = new URL(relativePath, import.meta.url);
        const source = readFileSync(filename, 'utf8');
        const { descriptor, errors } = parse(source, { filename: component });

        assert.deepEqual(errors, []);

        const script = descriptor.scriptSetup
            ? compileScript(descriptor, { id: `${component}-${relativePath}` })
            : null;
        const template = compileTemplate({
            id: `${component}-${relativePath}`,
            filename: component,
            source: descriptor.template?.content ?? '',
            compilerOptions: { bindingMetadata: script?.bindings ?? {} },
        });

        assert.deepEqual(template.errors, []);
    });
}

test('the support landing uses the shared next action and separates past awards', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserMonitoringPage.vue', import.meta.url), 'utf8');

    assert.match(source, /axios\.get\('\/dashboard\/monitoring\/data'/);
    assert.match(source, /:href="focus\.decision\.action\.href"/);
    assert.match(source, /data-support-schedule/);
    assert.match(source, /:src="record\.logo_url"/);
    assert.match(source, /<details v-if="support\.past\.length"/);
    assert.doesNotMatch(source, /\/dashboard\/applications\/data|Requirements due|monitoringNextStep/);
});

test('monitoring overview uses the dedicated contract and focused hierarchy', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserMonitoringDetailPage.vue', import.meta.url), 'utf8');
    const shell = readFileSync(new URL('../../resources/js/components/monitoring-detail/ApplicantMonitoringShell.vue', import.meta.url), 'utf8');
    const overview = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringOverviewRecord.vue', import.meta.url), 'utf8');

    assert.match(page, /\/dashboard\/monitoring\/\$\{applicationId\}\/data/);
    assert.match(page, /dataset\.monitoringRecord/);
    assert.match(page, /<MonitoringCheckIns/);
    assert.match(page, /<MonitoringHistory/);
    assert.doesNotMatch(page, /ApplicantRecipientMonitoring/);
    assert.doesNotMatch(page, /dashboard\/applications\/\$\{applicationId\}\/data/);
    assert.match(shell, /data-monitoring-detail-header/);
    assert.match(shell, /data-monitoring-detail-navigation/);
    assert.match(shell, /<ApplicantRecordTabs[^>]+layout="grid"/);
    assert.match(shell, /detail\.identity\.program\.logo_url/);
    assert.doesNotMatch(shell, /min-w-\[40rem\]|filipino-students-studying\.jpg|data-record-snapshot/);
    assert.match(overview, /data-monitoring-overview/);
    assert.match(overview, /<details v-if="showPlan" data-overview-plan/);
    assert.match(overview, /Support plan and agreement/);
    assert.match(overview, /data-overview-schedule/);
    assert.match(overview, /plan\.agreement\.href/);
    assert.ok(overview.indexOf('<MonitoringDecisionBanner') < overview.indexOf('data-overview-schedule'));
    assert.doesNotMatch(overview, /grid-cols-2/);

    const banner = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringDecisionBanner.vue', import.meta.url), 'utf8');
    assert.match(banner, /Your next step/);
    assert.match(banner, /:href="decision\.action\.href"/);
});

test('check-ins use a directory and focused record instead of the legacy all-at-once panel', () => {
    const directory = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringCheckIns.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringCheckInRecord.vue', import.meta.url), 'utf8');
    const adjustment = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringAdjustmentModal.vue', import.meta.url), 'utf8');

    assert.match(directory, /data-monitoring-check-ins/);
    assert.match(directory, /Open check-ins/);
    assert.match(directory, /Upcoming check-ins/);
    assert.match(directory, /Past check-ins/);
    assert.match(directory, /data-next-check-in-appointment/);
    assert.match(directory, /record\.record_key !== appointment\.value\?\.record_key/);
    assert.match(directory, /<MonitoringCheckInRecord/);
    assert.match(record, /data-monitoring-check-in-record/);
    assert.match(record, /data-check-in-preparation/);
    assert.match(record, /What to bring/);
    assert.match(record, /Recorded by the provider/);
    assert.match(record, /!props\.record\.handoff && !isPast\.value/);
    assert.match(record, /monitoring-requirements\/\$\{target\.requirement\.id\}\/submission/);
    assert.match(record, /monitoring-cycles\/\$\{props\.record\.id\}\/grade-record/);
    assert.match(record, /MonitoringCaseHistoryModal/);
    assert.match(record, /Recorded outcome/);
    assert.match(record, /record\.guidance\.actor_label/);
    assert.match(adjustment, /adjustment-request/);
});

test('benefit releases use a focused directory and preserve confirmation and issue reporting', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserMonitoringDetailPage.vue', import.meta.url), 'utf8');
    const directory = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringBenefits.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/BenefitReleaseRecord.vue', import.meta.url), 'utf8');
    const response = readFileSync(new URL('../../resources/js/components/monitoring-detail/BenefitReceiptResponseModal.vue', import.meta.url), 'utf8');

    assert.match(page, /<MonitoringBenefits/);
    assert.match(directory, /data-monitoring-benefits/);
    assert.match(directory, /Upcoming releases/);
    assert.match(directory, /Release updates/);
    assert.match(directory, /Past releases/);
    assert.match(record, /data-benefit-release-record/);
    assert.match(record, /What this release includes/);
    assert.match(record, /Provider resolution/);
    assert.match(record, /BenefitReceiptResponseModal/);
    assert.match(response, /benefit-release-records\/\$\{props\.release\.record_id\}\/response/);
    assert.match(record, /Report a problem/);
    assert.match(directory, /:release-id="content.selected.record_id"/);
    assert.match(response, /data.append\('response_type', 'confirmed'\)/);
});

test('check-in venues open the shared map using the current recipient appointment address', () => {
    const directory = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringCheckIns.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringCheckInRecord.vue', import.meta.url), 'utf8');
    const link = readFileSync(new URL('../../resources/js/components/LocationMapLink.vue', import.meta.url), 'utf8');
    for (const source of [directory, record]) {
        assert.match(source, /import LocationMapLink from '\.\.\/LocationMapLink\.vue'/);
    }
    assert.match(directory, /<LocationMapLink :address="appointment\.handoff\.location" :latitude="appointment\.handoff\.latitude" :longitude="appointment\.handoff\.longitude" :title="appointment\.title \|\| 'Check-in venue'"/);
    assert.match(record, /<LocationMapLink :address="record\.handoff\.location" :latitude="record\.handoff\.latitude" :longitude="record\.handoff\.longitude" :title="record\.title \|\| 'Check-in venue'"/);
    assert.match(link, /@click="open = true"/);
    assert.match(link, /<LocationMapModal :open="open"[^>]*:address="address"/);
    assert.match(link, /@close="open = false"/);
    assert.doesNotMatch(directory + record, /:address="(?:programTitle|program\.location|record\.location)"/);
});

test('support history uses a focused timeline and preserves applicant outcome responses', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserMonitoringDetailPage.vue', import.meta.url), 'utf8');
    const history = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringHistory.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/SupportOutcomeRecord.vue', import.meta.url), 'utf8');
    const response = readFileSync(new URL('../../resources/js/components/monitoring-detail/SupportOutcomeResponseModal.vue', import.meta.url), 'utf8');

    assert.match(page, /<MonitoringHistory/);
    assert.match(history, /data-monitoring-history/);
    assert.match(history, /Support timeline/);
    assert.match(history, /Review decision/);
    assert.match(history, /data-history-month/);
    assert.match(history, /aria-label="Filter support history"/);
    assert.match(history, /item.date_caption/);
    assert.match(record, /data-support-outcome-record/);
    assert.match(record, /Decision summary/);
    assert.match(record, /Provider resolution/);
    assert.match(record, /SupportOutcomeResponseModal/);
    assert.match(record, /RecipientCaseReviewLauncher/);
    assert.match(response, /useModalAccessibility/);
    assert.match(response, /support-decisions\/\$\{props\.outcome\.id\}\/response/);
    assert.match(response, /Request reconsideration/);
});

test('payout details use the eligible release form and a private submission', () => {
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/BenefitReleaseRecord.vue', import.meta.url), 'utf8');
    const form = readFileSync(new URL('../../resources/js/components/monitoring-detail/BenefitPayoutDetailsModal.vue', import.meta.url), 'utf8');

    assert.match(record, /BenefitPayoutDetailsModal v-if="payout\?\.submit_url"/);
    assert.match(form, /useModalAccessibility/);
    assert.match(form, /Registered mobile number/);
    assert.match(form, /Bank account number/);
    assert.match(form, /autocomplete="off"/);
    assert.match(form, /axios.post\(props.payout.submit_url/);
    assert.match(form, /No money was sent/);
    assert.doesNotMatch(form, /localStorage|sessionStorage/);
});
