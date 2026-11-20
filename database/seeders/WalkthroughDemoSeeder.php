<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use App\Models\User;
use Database\Seeders\Support\WalkthroughDemoCatalog as Catalog;
use Database\Seeders\Support\WorkflowDemoDocument;
use RuntimeException;

class WalkthroughDemoSeeder extends SingleProviderDemoSeeder
{
    protected const CATALOG = Catalog::class;
    protected string $assetDirectory = Catalog::DIRECTORY;
    protected string $platformPrefix = Catalog::ADMIN;
    protected ?string $learnerSchool = 'Silverleaf Crossing Senior High School';
    protected bool $seedProofForUnfinishedSession = false;

    public function run(): void
    {
        $this->assertDemoEnvironment();

        parent::run();
    }

    protected function assertDemoEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The walkthrough fixtures can only be seeded locally.');
        }
    }

    protected function program(User $provider, string $round, string $title, string $mark, int $variant, string $status): Scholarship
    {
        $program = parent::program($provider, $round, $title, $mark, $variant, $status);
        $catalog = static::CATALOG;
        if ($title === $catalog::programs()['onboarding'][0]) {
            $program->update(['onboarding_policy' => [
                'final_agreement_required' => true,
                'instructions' => 'Bring your school ID to the provider orientation or first collection visit. The scholarship desk handles the agreement and records completion after you sign.',
            ]]);
        }

        return $program;
    }

    protected function prepareAdditionalExamples(): void
    {
        $catalog = static::CATALOG;
        $record = $this->applications['one_time.'.$catalog::LEARNERS[1]]->benefitReleaseRecords()->latest('id')->firstOrFail();
        $record->release->update(['release_at' => $this->timeline->at(-2, 14)]);

        foreach ([
            'session-proof' => 'Sample Session Proof',
            'distribution-proof' => 'Sample Provider Distribution Proof',
            'receipt-proof' => 'Sample Recipient Receipt Evidence',
        ] as $name => $title) {
            WorkflowDemoDocument::make($this->assetDirectory.'/walkthrough-uploads/'.$name.'.pdf', $title, [
                'Provider: '.$catalog::PROVIDER_NAME,
                'This fixture can be uploaded in the local demonstration only.',
            ]);
        }
    }
}
