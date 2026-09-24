<?php

namespace Tests\Unit;

use App\Support\EmailAddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailAddressPolicyTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_it_identifies_addresses_that_can_receive_external_mail(
        string $email,
        bool $expected,
    ): void {
        $this->assertSame($expected, EmailAddressPolicy::canReceiveExternalMail($email));
    }

    public static function addresses(): array
    {
        return [
            'real mailbox' => ['student@gmail.com', true],
            'project mailbox' => ['support@findscholarship.online', true],
            'test domain' => ['student@scholarship.test', false],
            'invalid domain' => ['student@scholarship.invalid', false],
            'example domain' => ['student@scholarship.example', false],
            'localhost domain' => ['student@localhost', false],
            'invalid address' => ['not-an-email', false],
        ];
    }
}
