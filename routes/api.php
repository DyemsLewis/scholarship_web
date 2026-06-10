<?php

use App\Http\Controllers\Api\MobileAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function (): void {
    Route::post('/register', [MobileAuthController::class, 'register'])->name('mobile.register');
    Route::post('/login', [MobileAuthController::class, 'login'])->name('mobile.login');
    Route::get('/profile', [MobileAuthController::class, 'profile'])->name('mobile.profile');
    Route::post('/logout', [MobileAuthController::class, 'logout'])->name('mobile.logout');
});
