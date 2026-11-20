<?php

namespace Database\Seeders\Support;

class WalkthroughDemoCatalog extends SingleProviderDemoCatalog
{
    public const DIRECTORY = 'arvessia-walkthrough-demo-20261120';
    public const ADMIN = 'arv.demo';
    public const ROOT = 'admin';
    public const ADMIN_NAME = 'Admin';
    public const PROVIDER = 'arvessia';
    public const PROVIDER_BRAND = 'Arvessia';
    public const PROVIDER_NAME = 'Arvessia Learning Trust';
    public const PROVIDER_MARK = 'ALT';
    public const LEARNERS = ['nimori', 'orvessa', 'savilo', 'temariq'];
    public const PROFILE_LEARNERS = ['velune', 'weyaro'];

    public static function programs(): array
    {
        return [
            'intake' => ['Arvessia Learning Access Grant', 'ALA', 'intake', 'published'],
            'screening' => ['Arvessia Study Bridge Scholarship', 'ASB', 'screening', 'published'],
            'exam' => ['Arvessia Discovery Scholars Award', 'ADS', 'exam', 'published'],
            'interview' => ['Arvessia Community Leaders Grant', 'ACL', 'interview', 'published'],
            'decision' => ['Arvessia Future Pathways Scholarship', 'AFP', 'decision', 'published'],
            'support' => ['Arvessia Continuing Studies Award', 'ACS', 'support', 'published'],
            'renewal' => ['Arvessia Education Continuity Grant', 'AEC', 'support', 'published'],
            'one_time' => ['Arvessia School Kit Award', 'ASK', 'one_time', 'published'],
            'onboarding' => ['Arvessia First Term Partnership Grant', 'AFT', 'support', 'published'],
            'draft' => ['Arvessia Digital Access Grant', 'ADA', 'draft', 'draft'],
            'review' => ['Arvessia College Entry Award', 'ACE', 'review', 'pending_review'],
        ];
    }

    public static function scenarios(): array
    {
        $scenarios = array_map(static function (array $row): array {
            if ($row[1] === 'exam.complete') $row[3] = 'Complete the examination session, then attach proof.';

            return $row;
        }, parent::scenarios());

        return [...$scenarios, ['onboarding', 'agreement', static::LEARNERS[0], 'Confirm interest, then complete the final provider agreement.']];
    }
}
