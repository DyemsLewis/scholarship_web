<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderApplicationScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_queue_paginates_and_searches_twelve_hundred_applications_with_bounded_queries(): void
    {
        Mail::fake();
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Large Applicant Queue Program',
            'description' => 'Used to verify provider queue behavior at realistic volume.',
            'status' => 'published',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
        ]);
        $now = now();
        $password = Hash::make('password123');

        collect(range(1, 1200))
            ->chunk(200)
            ->each(function ($numbers) use ($now, $password): void {
                DB::table('users')->insert($numbers->map(function (int $number) use ($now, $password): array {
                    $identity = str_pad((string) $number, 4, '0', STR_PAD_LEFT);

                    return [
                        'email' => "scale-{$identity}@example.test",
                        'email_verified_at' => $now,
                        'password' => $password,
                        'username' => "scale_{$identity}",
                        'role' => 'applicant',
                        'account_status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all());
            });

        DB::table('users')
            ->where('email', 'like', 'scale-%@example.test')
            ->orderBy('id')
            ->pluck('id')
            ->chunk(200)
            ->each(function ($applicantIds) use ($scholarship, $now): void {
                DB::table('scholarship_applications')->insert($applicantIds->map(fn (int $applicantId): array => [
                    'scholarship_id' => $scholarship->id,
                    'applicant_id' => $applicantId,
                    'status' => 'submitted',
                    'workflow_version' => 2,
                    'application_state' => 'submitted',
                    'workflow_stage' => 'screening',
                    'eligibility_score' => 100,
                    'eligibility_breakdown' => json_encode(['score' => 100, 'criteria' => []]),
                    'dss_score' => 100,
                    'dss_recommendation' => 'highly_recommended',
                    'dss_breakdown' => json_encode([
                        'score' => 100,
                        'recommendation' => 'highly_recommended',
                        'criteria' => [],
                    ]),
                    'document_checklist' => json_encode([]),
                    'submitted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        DB::table('scholarship_applications')
            ->where('scholarship_id', $scholarship->id)
            ->orderBy('id')
            ->pluck('id')
            ->chunk(200)
            ->each(function ($applicationIds) use ($now): void {
                $stages = ['screening', 'formal_application', 'decision'];
                $rows = $applicationIds->flatMap(fn (int $applicationId) => collect($stages)
                    ->map(fn (string $stage, int $position): array => [
                        'scholarship_application_id' => $applicationId,
                        'stage_key' => $stage,
                        'position' => $position,
                        'status' => $stage === 'screening' ? 'current' : 'pending',
                        'started_at' => $stage === 'screening' ? $now : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]))
                    ->all();

                DB::table('application_stage_progresses')->insert($rows);
            });

        $queryCount = 0;
        $queryPatterns = [];
        DB::listen(function ($query) use (&$queryCount, &$queryPatterns): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queryCount++;
                $pattern = preg_replace('/\s+/', ' ', strtolower(trim($query->sql)));
                $queryPatterns[$pattern] = ($queryPatterns[$pattern] ?? 0) + 1;
            }
        });

        $firstPage = $this->actingAs($provider)
            ->getJson('/provider/applications/data?filter=pending_review&sort=oldest&page=1&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'applications')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 120)
            ->assertJsonPath('pagination.total', 1200)
            ->assertJsonPath('filter_counts.pending_review', 1200)
            ->assertJsonPath('filter_counts.all', 1200);

        arsort($queryPatterns);
        $querySummary = collect($queryPatterns)
            ->take(8)
            ->map(fn (int $count, string $sql): string => "{$count}x {$sql}")
            ->implode("\n");
        $this->assertLessThan(90, $queryCount, "Provider queue executed {$queryCount} SELECT queries for one page:\n{$querySummary}");

        $firstPageIds = collect($firstPage->json('applications'))->pluck('id');
        $secondPageIds = collect($this->actingAs($provider)
            ->getJson('/provider/applications/data?filter=pending_review&sort=oldest&page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'applications')
            ->assertJsonPath('pagination.current_page', 2)
            ->json('applications'))->pluck('id');

        $this->assertTrue($firstPageIds->intersect($secondPageIds)->isEmpty());

        $this->actingAs($provider)
            ->getJson('/provider/applications/data?filter=all&search=scale-1200%40example.test')
            ->assertOk()
            ->assertJsonCount(1, 'applications')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('applications.0.applicant.email', 'scale-1200@example.test');

        $this->actingAs($provider)
            ->getJson('/provider/applications/data?per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}
