<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;

$root = rtrim((string) (getenv('BROWSER_QA_APP_ROOT') ?: dirname(__DIR__, 2)), '/\\');

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$username = trim((string) ($argv[1] ?? ''));
$user = User::query()->where('username', $username)->first();

if (! $user) {
    fwrite(STDERR, "Browser QA user not found.\n");
    exit(1);
}

$session = $app->make('session')->driver();
$session->start();
Auth::guard('web')->login($user);
$session->save();

$cookieName = (string) config('session.cookie');
$encrypter = $app->make('encrypter');
$cookieValue = $encrypter->encrypt(
    CookieValuePrefix::create($cookieName, $encrypter->getKey()).$session->getId(),
    false,
);

echo json_encode([
    'name' => $cookieName,
    'value' => $cookieValue,
], JSON_THROW_ON_ERROR);
