<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class OpaqueRecordUrl
{
    private const ROOTS = 'dashboard|provider|admin|documents|service-files';

    public function encode(string $path): ?array
    {
        if (! $this->isSafePath($path) || ! preg_match('#^/('.self::ROOTS.')/#', $path, $root)) return null;
        preg_match_all('#/(?:[0-9]+|(?:check-in|academic|release|outcome)-[0-9]+)(?=/|$)#', $path, $matches, PREG_OFFSET_CAPTURE);
        if (! $matches[0]) return null;

        [$segment, $offset] = $matches[0][array_key_last($matches[0])];
        $prefix = substr($path, 0, $offset + strlen($segment));
        $token = rtrim(strtr(Crypt::encryptString('record-url:v1:'.$prefix), '+/', '-_'), '=');
        $opaquePrefix = '/'.$root[1].'/r/'.$token;

        return ['prefix' => $prefix, 'opaque_prefix' => $opaquePrefix, 'path' => $opaquePrefix.substr($path, strlen($prefix))];
    }

    public function decode(string $path): ?array
    {
        if (! preg_match('#^/('.self::ROOTS.')/r/([^/]+)(/.*)?$#D', $path, $matches)) return null;
        $token = $matches[2];
        if (strlen($token) > 2048 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $token)) throw new DecryptException('Invalid record link.');
        $payload = Crypt::decryptString(strtr($token, '-_', '+/'));
        if (! str_starts_with($payload, 'record-url:v1:')) throw new DecryptException('Invalid record link.');
        $prefix = substr($payload, strlen('record-url:v1:'));
        $suffix = $matches[3] ?? '';
        if (! $this->isSafePath($prefix.$suffix)
            || ! str_starts_with($prefix, '/'.$matches[1].'/')
            || ! preg_match('#/(?:[0-9]+|(?:check-in|academic|release|outcome)-[0-9]+)$#D', $prefix)) {
            throw new DecryptException('Invalid record link.');
        }

        return ['prefix' => $prefix, 'opaque_prefix' => '/'.$matches[1].'/r/'.$token, 'path' => $prefix.$suffix];
    }

    public function isOpaque(string $path): bool
    {
        return (bool) preg_match('#^/(?:'.self::ROOTS.')/r(?:/|$)#', $path);
    }

    private function isSafePath(string $path): bool
    {
        return strlen($path) <= 4096 && (bool) preg_match('#^/[A-Za-z0-9_/-]+$#D', $path) && ! str_contains($path, '//');
    }
}
