import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/pages/UserMonitoringDetailPage.vue',
    '../../resources/js/components/monitoring-detail/ApplicantMonitoringShell.vue',
    '../../resources/js/components/monitoring-detail/MonitoringDecisionBanner.vue',
    '../../resources/js/components/monitoring-detail/MonitoringOverviewRecord.vue',
    '../../resources/js/components/monitoring-detail/MonitoringCheckIns.vue',
    '../../resources/js/components/monitoring-detail/MonitoringCheckInRecord.vue',
    '../../resources/js/components/monitoring-detail/MonitoringAdjustmentModal.vue',
    '../../resources/js/components/monitoring-detail/MonitoringBenefits.vue',
    '../../resources/js/components/monitoring-detail/BenefitReleaseRecord.vue',
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
    assert.match(shell, /grid-cols-2/);
    assert.match(shell, /sm:grid-cols-4/);
    assert.doesNotMatch(shell, /min-w-\[40rem\]/);
    assert.match(shell, /filipino-students-studying\.jpg/);
    assert.match(shell, /lg:min-h-\[12rem\]/);
    assert.match(overview, /data-monitoring-overview/);
    assert.match(overview, /Support plan/);
    assert.match(overview, /Your support schedule/);
    assert.match(overview, /latest_support_decision\.href \|\| historyHref/);
    assert.doesNotMatch(overview, /grid-cols-2/);

    const banner = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringDecisionBanner.vue', import.meta.url), 'utf8');
    assert.match(banner, /decision\.state !== 'no_action'/);
});

test('check-ins use a directory and focused record instead of the legacy all-at-once panel', () => {
    const directory = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringCheckIns.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringCheckInRecord.vue', import.meta.url), 'utf8');
    const adjustment = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringAdjustmentModal.vue', import.meta.url), 'utf8');

    assert.match(directory, /data-monitoring-check-ins/);
    assert.match(directory, /Open check-ins/);
    assert.match(directory, /Upcoming check-ins/);
    assert.match(directory, /Completed check-ins/);
    assert.match(directory, /<MonitoringCheckInRecord/);
    assert.match(record, /data-monitoring-check-in-record/);
    assert.match(record, /Complete one item at a time/);
    assert.match(record, /monitoring-requirements\/\$\{target\.requirement\.id\}\/submission/);
    assert.match(record, /monitoring-cycles\/\$\{props\.record\.id\}\/grade-record/);
    assert.match(record, /MonitoringCaseHistoryModal/);
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
    assert.match(directory, /Reported release issues/);
    assert.match(directory, /Completed releases/);
    assert.match(record, /data-benefit-release-record/);
    assert.match(record, /What to know/);
    assert.match(record, /Provider resolution/);
    assert.match(record, /BenefitReceiptResponseModal/);
    assert.match(response, /benefit-release-records\/\$\{props\.release\.record_id\}\/response/);
    assert.match(response, /Report a problem/);
});

test('support history uses a focused timeline and preserves applicant outcome responses', () => {
    const page = readFileSync(new URL('../../resources/js/pages/UserMonitoringDetailPage.vue', import.meta.url), 'utf8');
    const history = readFileSync(new URL('../../resources/js/components/monitoring-detail/MonitoringHistory.vue', import.meta.url), 'utf8');
    const record = readFileSync(new URL('../../resources/js/components/monitoring-detail/SupportOutcomeRecord.vue', import.meta.url), 'utf8');
    const response = readFileSync(new URL('../../resources/js/components/monitoring-detail/SupportOutcomeResponseModal.vue', import.meta.url), 'utf8');

    assert.match(page, /<MonitoringHistory/);
    assert.match(history, /data-monitoring-history/);
    assert.match(history, /Monitoring timeline/);
    assert.match(history, /Review the latest support outcome/);
    assert.match(record, /data-support-outcome-record/);
    assert.match(record, /Decision summary/);
    assert.match(record, /Provider resolution/);
    assert.match(record, /SupportOutcomeResponseModal/);
    assert.match(response, /support-decisions\/\$\{props\.outcome\.id\}\/response/);
    assert.match(response, /Request reconsideration/);
});
