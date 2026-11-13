<?php

namespace Database\Seeders\Support;

final class WorkflowDemoCatalog
{
    public const CONFIRMATION = 'RESET_WORKFLOW_DEMO_20261113';
    public const DIRECTORY = 'workflow-demo-20261113';
    public const ROOT = 'novacore.root';
    public const ADMIN = 'novacore';
    public const PRIMARY = 'nuvareya';
    public const PARTNER = 'velasora';
    public const PENDING = 'kinoraya';
    public const APPLICANT = 'cohort.learner';

    public static function applicants(): array
    {
        $rows = [
            ['profile', 'Alira', 'Dawilang', 'Complete profile evidence', 'applicant'],
            ['verification', 'Brenel', 'Dumayun', 'Review submitted profile evidence', self::ADMIN.'.reviews'],
            ['apply', 'Ciela', 'Banlawi', 'Start a new application in the open round', 'applicant'],
            ['screening', 'Darel', 'Kalimbas', 'Review application evidence and record screening result', self::PRIMARY.'.reviewer'],
            ['formal', 'Elani', 'Talagsik', 'Record formal application verification', self::PRIMARY.'.selection'],
            ['exam.assign', 'Firan', 'Magsilay', 'Create or assign an examination session', self::PRIMARY.'.selection'],
            ['exam.confirm', 'Galia', 'Samilon', 'Acknowledge the published examination schedule', 'applicant'],
            ['exam.complete', 'Harel', 'Salinaw', 'Complete the conducted exam session', self::PRIMARY.'.selection'],
            ['exam.result', 'Ivara', 'Dalimpon', 'Record the examination result', self::PRIMARY.'.selection'],
            ['interview.assign', 'Jovin', 'Rimayag', 'Assign an interview session', self::PRIMARY.'.selection'],
            ['interview.confirm', 'Kalea', 'Kaluntog', 'Acknowledge the online interview schedule', 'applicant'],
            ['interview.result', 'Luvan', 'Silambay', 'Record the interview result', self::PRIMARY.'.selection'],
            ['decision', 'Mavina', 'Balungit', 'Record the final scholarship decision', self::PRIMARY.'.decisions'],
            ['agreement', 'Nilan', 'Ibayun', 'Respond to the recipient agreement', 'applicant'],
            ['release.plan', 'Orani', 'Sulatay', 'Schedule the first benefit release', self::PRIMARY.'.releases'],
            ['monitoring.appointment', 'Parel', 'Dumilay', 'View the upcoming in-person check-in appointment', 'applicant'],
            ['monitoring.review', 'Quinara', 'Balimpon', 'Record the first document result after the attended check-in', self::PRIMARY.'.monitoring'],
            ['monitoring.close', 'Revan', 'Dahilaw', 'Close the fully reviewed check-in case', self::PRIMARY.'.monitoring'],
            ['support.decision', 'Sarila', 'Labuntay', 'Record renewal or support completion', self::PRIMARY.'.recipients'],
            ['release.record', 'Tivan', 'Salintog', 'Record scheduled in-person distribution', self::PRIMARY.'.releases'],
            ['release.confirm', 'Ulara', 'Lagimbay', 'Confirm receipt of the released benefit', 'applicant'],
            ['onetime.plan', 'Vanel', 'Dagimtay', 'Schedule a one-time award without recurring check-ins', self::PRIMARY.'.releases'],
            ['onetime.record', 'Winara', 'Limangit', 'Record one-time award distribution', self::PRIMARY.'.releases'],
            ['onetime.confirm', 'Xavel', 'Dawantog', 'Confirm the received one-time award', 'applicant'],
            ['partner.screening', 'Yalina', 'Ilawig', 'Review a separate provider application', self::PARTNER.'.reviewer'],
            ['payout.details', 'Zeran', 'Simantay', 'Submit fictional bank payout details', 'applicant'],
            ['payout.review', 'Avelin', 'Binalaw', 'Review submitted fictional e-wallet details', self::PRIMARY.'.releases'],
            ['payout.transfer', 'Biran', 'Kalibay', 'Record transfer proof for an approved fictional payout destination', self::PRIMARY.'.releases'],
        ];

        return array_map(fn (array $row, int $index): array => [
            'scenario' => $row[0], 'username' => self::APPLICANT.'.'.$row[0],
            'first_name' => 'Learner', 'last_name' => sprintf('Profile %02d', $index + 1),
            'next_action' => $row[3], 'actor' => $row[4],
        ], $rows, array_keys($rows));
    }

    public static function roundFor(string $scenario): ?string
    {
        return match (str($scenario)->before('.')->toString()) {
            'profile', 'verification', 'apply' => null,
            'screening', 'formal' => 'screening',
            'exam' => 'exam',
            'interview' => 'interview',
            'decision' => 'decision',
            'onetime', 'payout' => 'one_time',
            'partner' => 'partner_screening',
            default => 'support',
        };
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
