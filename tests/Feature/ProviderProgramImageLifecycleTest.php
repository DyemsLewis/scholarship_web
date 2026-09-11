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

    private function pngContent(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
    }
}
