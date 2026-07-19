<?php

use App\Http\Controllers\Api\MobileAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->middleware('throttle:120,1')->group(function (): void {
    Route::post('/register', [MobileAuthController::class, 'register'])->middleware('throttle:5,1')->name('mobile.register');
    Route::post('/login', [MobileAuthController::class, 'login'])->middleware('throttle:10,1')->name('mobile.login');
    Route::get('/profile', [MobileAuthController::class, 'profile'])->name('mobile.profile');
    Route::patch('/profile', [MobileAuthController::class, 'updateProfile'])->name('mobile.profile.update');
    Route::get('/documents', [MobileAuthController::class, 'documents'])->name('mobile.documents');
    Route::post('/student-documents', [MobileAuthController::class, 'uploadPreparedDocument'])->middleware('throttle:20,1')->name('mobile.student-documents.store');
    Route::delete('/student-documents/{document}', [MobileAuthController::class, 'deletePreparedDocument'])->name('mobile.student-documents.destroy');
    Route::post('/applications', [MobileAuthController::class, 'storeApplication'])->name('mobile.applications.store');
    Route::patch('/applications/{application}/schedules/{schedule}/acknowledge', [MobileAuthController::class, 'acknowledgeApplicationSchedule'])->name('mobile.applications.schedules.acknowledge');
    Route::post('/scholarships/{scholarship}/save', [MobileAuthController::class, 'saveScholarship'])->name('mobile.scholarships.save');
    Route::delete('/scholarships/{scholarship}/save', [MobileAuthController::class, 'unsaveScholarship'])->name('mobile.scholarships.unsave');
    Route::patch('/notifications/{notification}/read', [MobileAuthController::class, 'markNotificationRead'])->name('mobile.notifications.read');
    Route::post('/logout', [MobileAuthController::class, 'logout'])->name('mobile.logout');
});
