<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderProgramImageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_logo_can_be_uploaded_and_reused_for_a_new_program(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $pathsToClean = [];

        try {
            $logoResponse = $this->actingAs($provider)
                ->post('/provider/profile/logo', [
                    'logo_file' => UploadedFile::fake()->image('provider-logo.png', 64, 64),
                ], ['HTTP_ACCEPT' => 'application/json'])
                ->assertOk();
            $providerLogoPath = $logoResponse->json('user.provider_logo_path');
            $pathsToClean[] = $providerLogoPath;

            $this->assertNotNull($providerLogoPath);
            $this->assertFileExists(public_path($providerLogoPath));
            $this->assertDatabaseHas('provider_profiles', [
                'user_id' => $provider->id,
                'logo_path' => $providerLogoPath,
            ]);

            $programResponse = $this->actingAs($provider)
                ->postJson('/provider/scholarships', [
                    'title' => 'Provider Logo Program',
                    'status' => 'draft',
                    'use_provider_logo' => true,
                ])
                ->assertCreated();
            $programLogoPath = $programResponse->json('scholarship.image_path');
            $pathsToClean[] = $programLogoPath;

            $this->assertNotSame($providerLogoPath, $programLogoPath);
            $this->assertFileExists(public_path($programLogoPath));
        } finally {
            foreach (array_unique(array_filter($pathsToClean)) as $path) {
                $absolutePath = public_path($path);

                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }
    }

    public function test_duplicated_program_uses_an_independent_logo_file(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $sourcePath = 'uploads/scholarships/test-source-'.Str::uuid().'.png';
        file_put_contents(public_path($sourcePath), $this->pngContent());
        $pathsToClean = [$sourcePath];

        try {
            $scholarship = Scholarship::create([
                'provider_id' => $provider->id,
                'image_path' => $sourcePath,
                'title' => 'Logo Lifecycle Program',
                'description' => 'A program used to verify independent duplicated logos.',
                'status' => 'draft',
            ]);

            $duplicateResponse = $this->actingAs($provider)
                ->postJson("/provider/scholarships/{$scholarship->id}/duplicate")
                ->assertCreated();
            $duplicate = Scholarship::query()->findOrFail($duplicateResponse->json('scholarship.id'));
            $copiedPath = $duplicate->image_path;
            $pathsToClean[] = $copiedPath;

            $this->assertNotSame($sourcePath, $copiedPath);
            $this->assertFileExists(public_path($sourcePath));
            $this->assertFileExists(public_path($copiedPath));

            $replacement = UploadedFile::fake()->image('replacement-logo.png', 32, 32);
            $updateResponse = $this->actingAs($provider)
                ->put("/provider/scholarships/{$duplicate->id}", [
                    'title' => $duplicate->title,
                    'status' => 'draft',
                    'image_file' => $replacement,
                ], ['HTTP_ACCEPT' => 'application/json'])
                ->assertOk();
            $replacementPath = $updateResponse->json('scholarship.image_path');
            $pathsToClean[] = $replacementPath;

            $this->assertSame($sourcePath, $scholarship->fresh()->image_path);
            $this->assertFileExists(public_path($sourcePath));
            $this->assertFileExists(public_path($replacementPath));
            $this->assertFileDoesNotExist(public_path($copiedPath));
        } finally {
            foreach (array_unique(array_filter($pathsToClean)) as $path) {
                $absolutePath = public_path($path);

                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
        }
    }

    public function test_provider_can_choose_a_system_background_and_replace_it_with_an_upload(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $uploadedPath = null;

        try {
            $this->actingAs($provider)
                ->getJson('/provider/cover-images')
                ->assertOk()
                ->assertJsonPath('recommended_key', 'campus-community')
                ->assertJsonCount(8, 'images');

            $this->actingAs($provider)
                ->postJson('/provider/profile/cover', [
                    'cover_source' => 'system',
                    'cover_key' => 'science-class',
                ])
                ->assertOk()
                ->assertJsonPath('cover.source', 'system')
                ->assertJsonPath('cover.key', 'science-class');

            $this->assertDatabaseHas('provider_profiles', [
                'user_id' => $provider->id,
                'cover_image_source' => 'system',
                'cover_image_key' => 'science-class',
                'cover_image_path' => null,
            ]);

            $response = $this->actingAs($provider)
                ->post('/provider/profile/cover', [
                    'cover_source' => 'upload',
                    'cover_file' => UploadedFile::fake()->image('organization-cover.jpg', 1200, 600),
                ], ['HTTP_ACCEPT' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('cover.source', 'upload')
                ->assertJsonPath('cover.is_custom', true);

            $uploadedPath = $provider->fresh()->providerProfile->cover_image_path;
            $this->assertNotNull($uploadedPath);
            $this->assertFileExists(public_path($uploadedPath));

            $this->actingAs($provider)
                ->postJson('/provider/profile/cover', ['cover_source' => 'default'])
                ->assertOk()
                ->assertJsonPath('cover.source', 'default');

            $this->assertFileDoesNotExist(public_path($uploadedPath));
        } finally {
            if ($uploadedPath && is_file(public_path($uploadedPath))) {
                @unlink(public_path($uploadedPath));
            }
        }
    }

    public function test_program_can_use_system_uploaded_and_organization_backgrounds(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'cover_image_source' => 'system',
            'cover_image_key' => 'campus-community',
        ]);
        $pathsToClean = [];

        try {
            $systemProgram = $this->actingAs($provider)
                ->postJson('/provider/scholarships', [
                    'title' => 'System Cover Program',
                    'category' => 'STEM scholarship',
                    'status' => 'draft',
                    'cover_source' => 'system',
                    'cover_key' => 'science-project',
                ])
                ->assertCreated()
                ->assertJsonPath('scholarship.cover_image_source', 'system')
                ->assertJsonPath('scholarship.cover_image_key', 'science-project');

            $this->assertStringContainsString(
                '/images/filipino-students-science-experiment.jpg',
                $systemProgram->json('scholarship.cover_image_url'),
            );

            $uploadResponse = $this->actingAs($provider)
                ->post('/provider/scholarships', [
                    'title' => 'Uploaded Cover Program',
                    'status' => 'draft',
                    'cover_source' => 'upload',
                    'cover_file' => UploadedFile::fake()->image('program-cover.jpg', 1200, 600),
                ], ['HTTP_ACCEPT' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('scholarship.cover_image_source', 'upload');
            $pathsToClean[] = $uploadResponse->json('scholarship.cover_image_path');
            $this->assertFileExists(public_path($pathsToClean[0]));

            $providerResponse = $this->actingAs($provider)
                ->postJson('/provider/scholarships', [
                    'title' => 'Organization Cover Program',
                    'status' => 'draft',
                    'cover_source' => 'provider',
                ])
                ->assertCreated()
                ->assertJsonPath('scholarship.cover_image_source', 'provider')
                ->assertJsonPath('scholarship.cover_image_key', 'campus-community');

            $this->assertStringContainsString(
                '/images/filipino-campus.jpg',
                $providerResponse->json('scholarship.cover_image_url'),
            );
        } finally {
            foreach (array_filter($pathsToClean) as $path) {
                if (is_file(public_path($path))) {
                    @unlink(public_path($path));
                }
            }
        }
    }

    public function test_scholarship_logo_is_saved_and_returned_separately_from_its_background(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $logoPath = null;

        try {
            $response = $this->actingAs($provider)
                ->post('/provider/scholarships', [
                    'title' => 'Independent Identity Program',
                    'status' => 'draft',
                    'image_file' => UploadedFile::fake()->image('program-logo.png', 256, 256),
                    'cover_source' => 'system',
                    'cover_key' => 'science-project',
                ], ['HTTP_ACCEPT' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('scholarship.cover_image_source', 'system')
                ->assertJsonPath('scholarship.cover_image_key', 'science-project');

            $logoPath = $response->json('scholarship.image_path');
            $logoUrl = $response->json('scholarship.logo_url');
            $coverUrl = $response->json('scholarship.cover_image_url');

            $this->assertNotNull($logoPath);
            $this->assertFileExists(public_path($logoPath));
            $this->assertStringContainsString($logoPath, $logoUrl);
            $this->assertNotSame($logoUrl, $coverUrl);

            $this->actingAs($provider)
                ->getJson('/provider/programs/data')
                ->assertOk()
                ->assertJsonPath('programs.0.logo_url', $logoUrl)
                ->assertJsonPath('programs.0.image_url', $logoUrl);
        } finally {
            if ($logoPath && is_file(public_path($logoPath))) {
                @unlink(public_path($logoPath));
            }
        }
    }

    public function test_submitting_a_program_requires_a_logo_and_background_independently(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Missing Logo Program',
                'description' => 'A complete submission must identify its scholarship separately from its background.',
                'status' => 'pending_review',
                'cover_source' => 'system',
                'cover_key' => 'science-project',
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_file');
    }

    private function pngContent(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }
}
