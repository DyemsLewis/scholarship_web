<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_entry_pages_expose_a_discoverable_manifest_without_authentication(): void
    {
        foreach (['/', '/login', '/register', '/provider/register'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('rel="manifest"', false)
                ->assertSee('manifest.webmanifest', false)
                ->assertSee('pwa/icon-192.png', false)
                ->assertSee('pwa/apple-touch-icon.png', false)
                ->assertSee('content="#081426"', false);
        }
    }

    public function test_pwa_metadata_does_not_remove_authenticated_page_cache_protection(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard/profile')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('rel="manifest"', false);
    }

    public function test_offline_assets_are_public_static_files_without_private_record_content(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('./', $manifest['start_url']);
        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], './'));
            $this->assertFileExists($path);
            $size = getimagesize($path);
            $this->assertSame($icon['sizes'], $size[0].'x'.$size[1]);
        }
        $notice = file_get_contents(public_path('offline.html'));
        $this->assertStringNotContainsString('csrf-token', $notice);
        $this->assertStringNotContainsString('portalUser', $notice);
        $this->assertStringContainsString('Reconnect to continue', $notice);
    }
}
