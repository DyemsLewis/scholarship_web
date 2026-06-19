<?php

use App\Http\Controllers\Api\MobileAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function (): void {
    Route::post('/register', [MobileAuthController::class, 'register'])->name('mobile.register');
    Route::post('/login', [MobileAuthController::class, 'login'])->name('mobile.login');
    Route::get('/profile', [MobileAuthController::class, 'profile'])->name('mobile.profile');
    Route::patch('/profile', [MobileAuthController::class, 'updateProfile'])->name('mobile.profile.update');
    Route::post('/applications', [MobileAuthController::class, 'storeApplication'])->name('mobile.applications.store');
    Route::post('/scholarships/{scholarship}/save', [MobileAuthController::class, 'saveScholarship'])->name('mobile.scholarships.save');
    Route::delete('/scholarships/{scholarship}/save', [MobileAuthController::class, 'unsaveScholarship'])->name('mobile.scholarships.unsave');
    Route::post('/logout', [MobileAuthController::class, 'logout'])->name('mobile.logout');
});
