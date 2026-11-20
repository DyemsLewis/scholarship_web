<?php

namespace Database\Seeders\Support;

final class HostedWalkthroughDemoCatalog extends WalkthroughDemoCatalog
{
    public const DIRECTORY = 'mirevalo-hosted-walkthrough-20261120';
    public const CONFIRMATION = 'SEED_ISOLATED_HOSTED_WALKTHROUGH_20261120';
    public const ADMIN = 'admin';
    public const PROVIDER = 'mirevalo';
    public const PROVIDER_BRAND = 'Mirevalo';
    public const PROVIDER_NAME = 'Mirevalo Education Guild';
    public const PROVIDER_MARK = 'MEG';
    public const LEARNERS = ['ziavon', 'cavelo', 'novema', 'yavik'];
    public const PROFILE_LEARNERS = ['veolin', 'mavero'];

    public static function programs(): array
    {
        return [
            'intake' => ['Mirevalo Open Study Grant', 'MOS', 'intake', 'published'],
            'screening' => ['Mirevalo Study Access Award', 'MSA', 'screening', 'published'],
            'exam' => ['Mirevalo Applied Learning Award', 'MAL', 'exam', 'published'],
            'interview' => ['Mirevalo Civic Scholars Program', 'MCS', 'interview', 'published'],
            'decision' => ['Mirevalo Future Scholars Award', 'MFS', 'decision', 'published'],
            'support' => ['Mirevalo Learning Continuity Grant', 'MLC', 'support', 'published'],
            'renewal' => ['Mirevalo Term Progress Award', 'MTP', 'support', 'published'],
            'one_time' => ['Mirevalo School Start Kit', 'MSS', 'one_time', 'published'],
            'onboarding' => ['Mirevalo Orientation Support Grant', 'MOR', 'support', 'published'],
            'draft' => ['Mirevalo Digital Study Grant', 'MDS', 'draft', 'draft'],
            'review' => ['Mirevalo College Readiness Award', 'MCR', 'review', 'pending_review'],
        ];
    }

    public static function scenarios(): array
    {
        return array_map(static function (array $row): array {
            if ($row[0] === 'support' && $row[2] === self::LEARNERS[1]) {
                $row[1] = 'release.bank';
                $row[3] = 'Open the due bank-transfer release with approved fictional payout details.';
            }

            return $row;
        }, parent::scenarios());
    }
}
