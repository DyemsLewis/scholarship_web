<?php

namespace App\Support;

final class EmailAddressPolicy
{
    private const RESERVED_TOP_LEVEL_DOMAINS = [
        'example',
        'invalid',
        'localhost',
        'test',
    ];

    public static function canReceiveExternalMail(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $domain = strtolower((string) strrchr($email, '@'));
        $domain = ltrim($domain, '@');
        $topLevelDomain = strtolower((string) pathinfo($domain, PATHINFO_EXTENSION));

        return ! in_array($topLevelDomain ?: $domain, self::RESERVED_TOP_LEVEL_DOMAINS, true);
    }
}
