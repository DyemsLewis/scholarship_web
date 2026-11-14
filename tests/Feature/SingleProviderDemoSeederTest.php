<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Database\Seeders\SingleProviderDemoSeeder;
use Database\Seeders\Support\SingleProviderDemoAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SingleProviderDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_provider_and_four_shared_applicants_cover_the_demo_without_deciding_current_outcomes(): void
    {
        Storage::fake('local');
        $this->seed(SingleProviderDemoSeeder::class);
        $report = SingleProviderDemoAudit::inspect();
        $this->assertSame(1, $report['provider_organizations']);
        $this->assertSame(6, $report['applicants']);
        $this->assertSame(25, $report['applications']);
        $this->assertSame([], $report['timeline']['violations']);
        $this->assertSame('lumenops.root', User::findOrFail(1)->username);
        $this->assertSame(10, Scholarship::count());
        $this->assertSame(4, ScholarshipApplication::where('workflow_stage', 'decision')->whereNull('final_outcome')->count());
        $this->assertGreaterThanOrEqual(6, ScholarshipApplication::where('applicant_id', User::where('username', 'learner.aurora')->sole()->id)->count());
    }
}
