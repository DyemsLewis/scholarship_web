<?php

namespace Database\Seeders\Support;

final class SingleProviderDemoCatalog
{
    public const DIRECTORY = 'single-provider-demo-20261114';
    public const CONFIRMATION = 'RESET_SINGLE_PROVIDER_DEMO_20261114';
    public const ADMIN = 'lumenops';
    public const PROVIDER = 'orivanta';
    public const LEARNERS = ['aurora', 'brio', 'cielo', 'dune'];

    public static function scenarios(): array
    {
        return [
            ['screening', 'screening', 'aurora', 'Review the submitted files and record pre-screening.'],
            ['screening', 'formal', 'brio', 'Arrange formal verification and record the result.'],
            ['exam', 'exam.result', 'aurora', 'Record the completed examination outcome and evaluation.'],
            ['exam', 'exam.assign', 'brio', 'Assign the applicant to an examination session.'],
            ['exam', 'exam.confirm', 'cielo', 'Acknowledge the upcoming examination schedule.'],
            ['exam', 'exam.complete', 'dune', 'Attendance and proof are present; complete the examination session.'],
            ['interview', 'interview.result', 'aurora', 'Record the completed interview outcome and evaluation.'],
            ['interview', 'interview.confirm', 'brio', 'Acknowledge the upcoming online interview.'],
            ['interview', 'interview.assign', 'cielo', 'Assign an interview session.'],
            ['decision', 'decision', 'aurora', 'Compare the first scored candidate; the tester records the final decision.'],
            ['decision', 'decision', 'brio', 'Compare the second scored candidate; the tester records the final decision.'],
            ['decision', 'decision', 'cielo', 'Compare the third scored candidate; the tester chooses selected or waitlisted.'],
            ['decision', 'decision', 'dune', 'Record the missing interview score before final comparison.'],
            ['support', 'agreement', 'aurora', 'Review and accept the recipient agreement.'],
            ['support', 'release.plan', 'brio', 'Schedule the first allowance release.'],
            ['support', 'monitoring.review', 'cielo', 'Review original documents after the attended check-in.'],
            ['support', 'monitoring.close', 'dune', 'Close the check-in whose requirements are met.'],
            ['renewal', 'release.confirm', 'aurora', 'Confirm receipt of the released allowance.'],
            ['renewal', 'support.decision', 'brio', 'Renew or complete support after closed checks and confirmed benefits.'],
            ['renewal', 'monitoring.appointment', 'cielo', 'View the upcoming document handoff and map.'],
            ['renewal', 'payout.review', 'dune', 'Review the submitted fictional e-wallet destination.'],
            ['one_time', 'onetime.plan', 'aurora', 'Schedule the one-time award; no recurring monitoring is required.'],
            ['one_time', 'onetime.record', 'brio', 'Record distribution for the scheduled one-time award.'],
            ['one_time', 'onetime.confirm', 'cielo', 'Confirm receipt of the released one-time award.'],
            ['one_time', 'onetime.complete', 'dune', 'Complete the award after its receipt has been confirmed.'],
        ];
    }
}
