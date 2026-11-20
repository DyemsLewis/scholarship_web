<?php

namespace Database\Seeders;

use App\Services\RecipientBenefitPayoutService;
use Database\Seeders\Support\HostedWalkthroughDemoCatalog as Catalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HostedWalkthroughDemoSeeder extends WalkthroughDemoSeeder
{
    protected const CATALOG = Catalog::class;
    protected string $assetDirectory = Catalog::DIRECTORY;
    protected string $platformPrefix = Catalog::ADMIN;
    protected ?string $learnerSchool = 'Northglen Passage Senior High School';

    protected function assertDemoEnvironment(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            || ! str_ends_with($connection->getDatabaseName(), '_fs_demo_20261120')
            || getenv('SINGLE_PROVIDER_DEMO_CONFIRM') !== Catalog::CONFIRMATION
            || strlen(getenv('WORKFLOW_DEMO_PASSWORD') ?: '') < 16) {
            throw new RuntimeException('Use an empty, dedicated hosted demo database and a strong temporary demo password. Never seed the live database.');
        }
    }

    protected function prepareAdditionalExamples(): void
    {
        parent::prepareAdditionalExamples();

        $application = $this->applications['support.'.Catalog::LEARNERS[1]];
        $this->release($application, false, RecipientBenefitPayoutService::BANK, 'approved');
        $application->benefitReleaseRecords()->latest('id')->firstOrFail()->release
            ->update(['release_at' => $this->timeline->at(-2, 16)]);
    }
}
