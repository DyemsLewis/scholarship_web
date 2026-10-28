<?php

namespace Database\Seeders\Support;

final class WorkflowDemoCatalog
{
    public const CONFIRMATION = 'RESET_WORKFLOW_DEMO_20261028';
    public const DIRECTORY = 'workflow-demo-20261028';

    public static function applicants(): array
    {
        $rows = [
            ['profile', 'Arielle', 'Mercado', 'Complete profile evidence', 'applicant'],
            ['verification', 'Benedict', 'Salcedo', 'Review submitted profile evidence', 'platform.reviews'],
            ['apply', 'Cassandra', 'Miraflor', 'Start a new application', 'applicant'],
            ['screening', 'Dario', 'Valmores', 'Review application evidence and record screening result', 'marilag.reviewer'],
            ['formal', 'Estella', 'Sandoval', 'Record formal application verification', 'marilag.selection'],
            ['exam.assign', 'Felix', 'Talavera', 'Create or assign an examination session', 'marilag.selection'],
            ['exam.confirm', 'Giselle', 'Maceda', 'Acknowledge the published examination schedule', 'applicant'],
            ['exam.complete', 'Hugo', 'Candelaria', 'Complete the conducted exam session', 'marilag.selection'],
            ['exam.result', 'Isabel', 'Montano', 'Record the examination result', 'marilag.selection'],
            ['interview.assign', 'Joaquin', 'Fajardo', 'Assign an interview session', 'marilag.selection'],
            ['interview.confirm', 'Kiara', 'Natividad', 'Acknowledge the online interview schedule', 'applicant'],
            ['interview.result', 'Leandro', 'Villacorta', 'Record the interview result', 'marilag.selection'],
            ['decision', 'Maelle', 'Esquivel', 'Record the final scholarship decision', 'marilag.decisions'],
            ['agreement', 'Nestor', 'Ledesma', 'Respond to the recipient agreement', 'applicant'],
            ['release.plan', 'Odessa', 'Balmoria', 'Schedule the first benefit release', 'marilag.releases'],
            ['monitoring.upload', 'Paolo', 'Manansala', 'Submit the current check-in documents', 'applicant'],
            ['monitoring.review', 'Querida', 'Tolentino', 'Review submitted check-in evidence', 'marilag.monitoring'],
            ['monitoring.close', 'Rafael', 'Vergara', 'Close the fully reviewed monitoring case', 'marilag.monitoring'],
            ['support.decision', 'Sabina', 'Ocampo', 'Record renewal or support completion', 'marilag.recipients'],
            ['release.record', 'Tomas', 'Evangelista', 'Record scheduled benefit distribution', 'marilag.releases'],
            ['release.confirm', 'Yasmin', 'Alcantara', 'Confirm receipt of the released benefit', 'applicant'],
            ['onetime.plan', 'Xavier', 'De Vera', 'Schedule a one-time award without recurring monitoring', 'marilag.releases'],
            ['onetime.record', 'Zenaida', 'Medel', 'Record one-time award distribution', 'marilag.releases'],
            ['onetime.confirm', 'Warren', 'Suico', 'Confirm the received one-time award', 'applicant'],
            ['partner.screening', 'Victoria', 'Pineda', 'Review a separate provider application', 'silayan.reviewer'],
        ];

        return array_map(fn (array $row): array => [
            'scenario' => $row[0], 'username' => 'learner.'.$row[0],
            'first_name' => $row[1], 'last_name' => $row[2],
            'next_action' => $row[3], 'actor' => $row[4],
        ], $rows);
    }

    public static function providerRoles(): array
    {
        return [
            'manager' => ['Provider Manager', null],
            'programs' => ['Program Coordinator', 'manage_programs'],
            'reviewer' => ['Application Reviewer', 'verify_applications'],
            'selection' => ['Selection Officer', 'manage_selection_activities'],
            'decisions' => ['Decision Officer', 'record_final_decisions'],
            'recipients' => ['Recipient Officer', 'manage_recipients'],
            'monitoring' => ['Monitoring Officer', 'manage_monitoring'],
            'releases' => ['Benefit Release Officer', 'manage_benefit_releases'],
            'profile' => ['Organization Profile Manager', 'manage_profile'],
            'team' => ['Team Administrator', 'manage_team'],
            'support' => ['Support Staff', 'manage_reports'],
            'billing' => ['Billing Staff', 'manage_billing'],
        ];
    }
}
