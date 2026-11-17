<?php

namespace Tests\Feature;

use Tests\TestCase;

class AndroidAssetLinksTest extends TestCase
{
    public function test_public_android_association_identifies_the_signed_application(): void
    {
        $statements = json_decode(
            file_get_contents(public_path('.well-known/assetlinks.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($statements);
        $this->assertNotEmpty($statements);
        foreach ($statements as $statement) {
            $this->assertContains('delegate_permission/common.handle_all_urls', $statement['relation']);
            $this->assertSame('android_app', $statement['target']['namespace']);
            $this->assertSame('online.findscholarship.twa', $statement['target']['package_name']);
            $this->assertNotEmpty($statement['target']['sha256_cert_fingerprints']);
            foreach ($statement['target']['sha256_cert_fingerprints'] as $fingerprint) {
                $this->assertMatchesRegularExpression('/\A(?:[A-F0-9]{2}:){31}[A-F0-9]{2}\z/', $fingerprint);
            }
        }
    }
}
