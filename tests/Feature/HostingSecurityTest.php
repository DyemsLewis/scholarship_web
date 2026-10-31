<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\StudentDocument;
use App\Models\User;
use App\Support\OpaqueRecordUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HostingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    public function test_opaque_references_round_trip_nested_and_composite_record_paths(): void
    {
        $codec = app(OpaqueRecordUrl::class);
        foreach (['/provider/programs/12/edit/benefits', '/dashboard/applications/23/history', '/admin/workspaces/accounts/52/data',
            '/dashboard/monitoring/23/check-ins/check-in-17', '/provider/applications/23/documents/99/view'] as $path) {
            $context = $codec->encode($path);
            $this->assertNotNull($context);
            $this->assertSame($path, $codec->decode($context['path'])['path']);
            $this->assertDoesNotMatchRegularExpression('#/[0-9]+(?:/|$)#', $context['path']);
            $this->assertNotSame($context['path'], $codec->encode($path)['path']);
        }
    }

    public function test_external_signed_nonrecord_and_unsafe_paths_are_never_tokenized(): void
    {
        $codec = app(OpaqueRecordUrl::class);
        foreach (['https://outside.example/12', '//outside.example/12', '/email/verify/12/hash', '/provider/register',
            '/webhooks/paymongo', '/provider/programs/12/../delete', '/provider/programs/12%2fedit', '/public/uploads/12.jpg'] as $path) {
            $this->assertNull($codec->encode($path), $path);
        }
    }

    public function test_authorized_browser_record_navigation_redirects_to_opaque_link_and_loads_the_same_record(): void
    {
        [$provider, $purchase] = $this->service();
        $response = $this->actingAs($provider)->get('/provider/billing/'.$purchase->id.'/files?section=delivery', ['Sec-Fetch-Dest' => 'document']);
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringContainsString('/provider/r/', $url);
        $this->assertStringEndsWith('/files?section=delivery', $url);
        $this->get($url)->assertOk()->assertViewIs('provider-service-workspace')->assertSee('portal-route-opaque-prefix');
        $opaque = app(OpaqueRecordUrl::class)->encode('/provider/billing/'.$purchase->id.'/data')['path'];
        $this->getJson($opaque)->assertOk()->assertJsonPath('purchase.id', $purchase->id);
    }

    public function test_opaque_record_links_do_not_bypass_organization_scope(): void
    {
        [, $purchase] = $this->service();
        $other = User::factory()->create(['role' => 'provider']);
        $path = '/provider/billing/'.$purchase->id.'/data';
        $opaque = app(OpaqueRecordUrl::class)->encode($path)['path'];
        $plainStatus = $this->actingAs($other)->getJson($path)->status();
        $this->assertContains($plainStatus, [403, 404]);
        $this->getJson($opaque)->assertStatus($plainStatus);
    }

    public function test_opaque_record_links_do_not_bypass_role_permissions_or_authentication(): void
    {
        [$provider, $purchase] = $this->service();
        $opaque = app(OpaqueRecordUrl::class)->encode('/provider/billing/'.$purchase->id.'/data')['path'];
        $this->getJson($opaque)->assertUnauthorized();
        $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['manage_programs']]);
        $this->actingAs($staff)->getJson($opaque)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($opaque)->assertForbidden();
    }

    public function test_tampered_invalid_and_wrong_area_tokens_return_not_found(): void
    {
        $valid = app(OpaqueRecordUrl::class)->encode('/provider/billing/12')['path'];
        foreach (['/provider/r/invalid', $valid.'x', str_replace('/provider/r/', '/admin/r/', $valid), '/provider/r/'.str_repeat('a', 2050), $valid.'/../data'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $wrongPurpose = rtrim(strtr(Crypt::encryptString('not-a-record-link'), '+/', '-_'), '=');
        $this->getJson('/provider/r/'.$wrongPurpose)->assertNotFound();
    }

    public function test_applicant_document_ownership_and_private_cache_headers_survive_tokenization(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Storage::disk('local')->put('prepared/test.pdf', '%PDF-1.4 test');
        $document = StudentDocument::create(['user_id' => $owner->id, 'document_name' => 'School record', 'original_name' => 'test.pdf',
            'path' => 'prepared/test.pdf', 'mime_type' => 'application/pdf', 'size' => 13, 'uploaded_at' => now()]);
        $opaque = app(OpaqueRecordUrl::class)->encode('/dashboard/student-documents/'.$document->id.'/view')['path'];
        $this->actingAs($owner)->get($opaque)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/dashboard/student-documents/'.$document->id.'/view', ['Sec-Fetch-Dest' => 'document'])->assertRedirect();
        $this->actingAs($other)->get($opaque)->assertForbidden();
    }

    public function test_admin_record_routes_keep_their_permission_checks(): void
    {
        $root = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();
        $allowed = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_accounts']]);
        $denied = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_reports']]);
        $opaque = app(OpaqueRecordUrl::class)->encode('/admin/workspaces/accounts/'.$target->id.'/data')['path'];
        $this->actingAs($allowed)->getJson($opaque)->assertOk();
        $this->actingAs($denied)->getJson($opaque)->assertForbidden();
    }

    public function test_production_headers_allow_internal_previews_and_camera_but_require_nonce_scripts(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $response = $this->get('https://localhost/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Cache-Control', 'no-store, private');
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-src 'self' blob:", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $matches);
        $this->assertNotEmpty($matches[1]);
        $response->assertSee('nonce="'.$matches[1].'"', false);
        $this->assertStringContainsString('camera=(self)', $response->headers->get('Permissions-Policy'));
    }

    public function test_failed_logins_are_limited_per_account_even_when_the_ip_changes(): void
    {
        $user = User::factory()->create();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->postJson('/login', ['login' => $user->username, 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])->postJson('/login', ['login' => $user->username, 'password' => 'password'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_security_changes_do_not_add_new_csrf_exemptions(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertMatchesRegularExpression("/validateCsrfTokens\(except: \[\s*'webhooks\/paymongo',\s*\]\)/", $bootstrap);
    }

    public function test_new_program_drafts_issue_navigation_context_without_changing_database_identifiers(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $response = $this->actingAs($provider)->postJson('/provider/scholarships', [
            'title' => 'Secure draft navigation', 'description' => 'Test the first saved program step.', 'status' => 'draft',
        ])->assertCreated();
        $id = $response->json('scholarship.id');
        $this->assertIsInt($id);
        $this->assertSame('/provider/programs/'.$id, $response->json('record_navigation.prefix'));
        $this->assertSame('/provider/programs/'.$id, app(OpaqueRecordUrl::class)->decode($response->json('record_navigation.path'))['path']);
    }

    private function service(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = ProviderServicePurchase::create(['provider_id' => $provider->id, 'created_by' => $provider->id,
            'plan_code' => 'assisted_setup', 'plan_name' => 'Assisted program setup', 'amount' => 75000, 'currency' => 'PHP',
            'status' => 'paid', 'paid_at' => now(), 'fulfillment_status' => 'in_progress', 'reference_number' => 'SECURITY-'.$provider->id]);
        return [$provider, $purchase];
    }
}
