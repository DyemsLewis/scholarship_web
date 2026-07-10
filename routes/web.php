<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicantDashboardController;
use App\Http\Controllers\ApplicationDocumentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/login', [PageController::class, 'login'])->name('login');
Route::get('/forgot-password', [PageController::class, 'forgotPassword'])->name('password.request');
Route::get('/reset-password', [PageController::class, 'resetPassword'])->name('password.reset');
Route::get('/register', [PageController::class, 'register'])->name('register');
Route::get('/provider/register', [PageController::class, 'providerRegister'])->name('provider.register');
Route::redirect('/terms', '/');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])->middleware(['auth', 'throttle:6,1'])->name('verification.send');
Route::get('/account/setup', [PageController::class, 'accountSetup'])->middleware('auth')->name('account.setup');
Route::get('/dashboard', [ApplicantDashboardController::class, 'index'])->middleware('auth')->name('dashboard');
Route::get('/dashboard/scholarships', [ApplicantDashboardController::class, 'scholarships'])->middleware('auth')->name('dashboard.scholarships');
Route::get('/dashboard/scholarships/{scholarship}', [ApplicantDashboardController::class, 'scholarshipDetail'])->middleware('auth')->name('dashboard.scholarships.show');
Route::get('/dashboard/scholarships/{scholarship}/data', [ApplicantDashboardController::class, 'scholarshipDetailData'])->middleware('auth')->name('dashboard.scholarships.show.data');
Route::get('/dashboard/applications', [ApplicantDashboardController::class, 'applications'])->middleware('auth')->name('dashboard.applications');
Route::get('/dashboard/documents', [ApplicantDashboardController::class, 'documents'])->middleware('auth')->name('dashboard.documents');
Route::get('/dashboard/profile', [ApplicantDashboardController::class, 'profile'])->middleware('auth')->name('dashboard.profile');
Route::patch('/dashboard/profile', [ApplicantDashboardController::class, 'updateProfile'])->middleware('auth')->name('dashboard.profile.update');
Route::get('/dashboard/data', [ApplicantDashboardController::class, 'data'])->middleware('auth')->name('dashboard.data');
Route::get('/dashboard/applications/data', [ApplicantDashboardController::class, 'applicationsData'])->middleware('auth')->name('dashboard.applications.data');
Route::get('/dashboard/applications/{application}', [ApplicantDashboardController::class, 'applicationDetail'])->middleware('auth')->name('dashboard.applications.show');
Route::get('/dashboard/applications/{application}/data', [ApplicantDashboardController::class, 'applicationDetailData'])->middleware('auth')->name('dashboard.applications.show.data');
Route::get('/dashboard/documents/data', [ApplicantDashboardController::class, 'documentsData'])->middleware('auth')->name('dashboard.documents.data');
Route::post('/dashboard/student-documents', [ApplicantDashboardController::class, 'uploadPreparedDocument'])->middleware(['auth', 'throttle:20,1'])->name('dashboard.student-documents.store');
Route::get('/dashboard/student-documents/{document}/view', [ApplicantDashboardController::class, 'viewPreparedDocument'])->middleware('auth')->name('dashboard.student-documents.view');
Route::get('/dashboard/student-documents/{document}/download', [ApplicantDashboardController::class, 'downloadPreparedDocument'])->middleware('auth')->name('dashboard.student-documents.download');
Route::delete('/dashboard/student-documents/{document}', [ApplicantDashboardController::class, 'deletePreparedDocument'])->middleware('auth')->name('dashboard.student-documents.destroy');
Route::post('/dashboard/applications', [ApplicantDashboardController::class, 'storeApplication'])->middleware(['auth', 'throttle:10,1'])->name('dashboard.applications.store');
Route::patch('/dashboard/applications/{application}/response', [ApplicantDashboardController::class, 'respondToApplication'])->middleware('auth')->name('dashboard.applications.response');
Route::post('/dashboard/applications/{application}/documents', [ApplicantDashboardController::class, 'uploadDocument'])->middleware('auth')->name('dashboard.applications.documents.store');
Route::delete('/dashboard/documents/{document}', [ApplicantDashboardController::class, 'deleteDocument'])->middleware('auth')->name('dashboard.documents.destroy');
Route::post('/dashboard/scholarships/{scholarship}/save', [ApplicantDashboardController::class, 'saveScholarship'])->middleware('auth')->name('dashboard.scholarships.save');
Route::delete('/dashboard/scholarships/{scholarship}/save', [ApplicantDashboardController::class, 'unsaveScholarship'])->middleware('auth')->name('dashboard.scholarships.unsave');
Route::get('/documents/{document}/view', [ApplicationDocumentController::class, 'view'])->middleware('auth')->name('documents.view');
Route::get('/documents/{document}/download', [ApplicationDocumentController::class, 'download'])->middleware('auth')->name('documents.download');
Route::get('/notifications', [NotificationController::class, 'index'])->middleware('auth')->name('notifications.index');
Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('auth')->name('notifications.read-all');
Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('auth')->name('notifications.read');
Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/manage-users', [AdminController::class, 'manageUsers'])->name('manage-users');
        Route::get('/accounts/create', [AdminController::class, 'accountForm'])->name('accounts.create');
        Route::get('/accounts/{user}/edit', [AdminController::class, 'accountForm'])->name('accounts.edit');
        Route::get('/profile', [AdminController::class, 'profile'])->name('profile');
        Route::get('/reviews', [AdminController::class, 'reviews'])->name('reviews');
        Route::get('/logs', [AdminController::class, 'logs'])->name('logs');
        Route::get('/platform-analytics', [AdminController::class, 'platformAnalytics'])->name('platform-analytics');
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->name('users.show');
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus'])->name('users.status');
        Route::post('/users/{user}/force-password-reset', [AdminController::class, 'forcePasswordReset'])->name('users.force-password-reset');
        Route::patch('/users/{user}/email-verification', [AdminController::class, 'verifyUserEmail'])->name('users.email-verification');
        Route::post('/users/{user}/verification-email', [AdminController::class, 'resendUserVerificationEmail'])->name('users.verification-email');
        Route::get('/profile/data', [AdminController::class, 'profileData'])->name('profile.data');
        Route::patch('/profile', [AdminController::class, 'updateProfile'])->name('profile.update');
        Route::get('/analytics', [AdminController::class, 'analytics'])->name('analytics');
        Route::get('/reviews/data', [AdminController::class, 'reviewsData'])->name('reviews.data');
        Route::patch('/providers/{provider}/verification', [AdminController::class, 'updateProviderVerification'])->name('providers.verification');
        Route::patch('/scholarships/{scholarship}/review', [AdminController::class, 'updateScholarshipReview'])->name('scholarships.review');
        Route::get('/provider-verification-documents/{document}/download', [AdminController::class, 'downloadProviderVerificationDocument'])->name('provider-verification-documents.download');
        Route::get('/export/users', [AdminController::class, 'exportUsers'])->name('export.users');
        Route::get('/export/applications', [AdminController::class, 'exportApplications'])->name('export.applications');
        Route::get('/log-entries', [AdminController::class, 'logEntries'])->name('log-entries');
    });

Route::middleware(['auth', 'provider'])
    ->prefix('provider')
    ->name('provider.')
    ->group(function (): void {
        Route::get('/', [ProviderController::class, 'index'])->name('index');
        Route::get('/programs', [ProviderController::class, 'programs'])->name('programs');
        Route::get('/programs/create', [ProviderController::class, 'programForm'])->name('programs.create');
        Route::get('/programs/{scholarship}/edit', [ProviderController::class, 'programForm'])->name('programs.edit');
        Route::get('/applications', [ProviderController::class, 'applications'])->name('applications');
        Route::get('/profile', [ProviderController::class, 'profile'])->name('profile');
        Route::redirect('/insights', '/provider/review')->name('insights.redirect');
        Route::get('/review', [ProviderController::class, 'insights'])->name('review');
        Route::get('/profile/data', [ProviderController::class, 'profileData'])->name('profile.data');
        Route::patch('/profile', [ProviderController::class, 'updateProfile'])->name('profile.update');
        Route::post('/verification-documents', [ProviderController::class, 'uploadVerificationDocument'])->name('verification-documents.store');
        Route::get('/verification-documents/{document}/download', [ProviderController::class, 'downloadVerificationDocument'])->name('verification-documents.download');
        Route::delete('/verification-documents/{document}', [ProviderController::class, 'deleteVerificationDocument'])->name('verification-documents.destroy');
        Route::get('/insights/data', [ProviderController::class, 'insightsData'])->name('insights.data');
        Route::get('/applications/data', [ProviderController::class, 'applicationsData'])->name('applications.data');
        Route::get('/applications/{application}', [ProviderController::class, 'applicationDetail'])->whereNumber('application')->name('applications.show');
        Route::get('/applications/{application}/data', [ProviderController::class, 'applicationDetailData'])->whereNumber('application')->name('applications.show.data');
        Route::patch('/applications/{application}/status', [ProviderController::class, 'updateApplicationStatus'])->name('applications.status');
        Route::patch('/documents/{document}/status', [ProviderController::class, 'updateDocumentStatus'])->name('documents.status');
        Route::get('/export/applications', [ProviderController::class, 'exportApplications'])->name('export.applications');
        Route::get('/scholarships', [ProviderController::class, 'scholarships'])->name('scholarships');
        Route::post('/scholarships', [ProviderController::class, 'storeScholarship'])->name('scholarships.store');
        Route::get('/scholarships/{scholarship}', [ProviderController::class, 'showScholarship'])->name('scholarships.show');
        Route::put('/scholarships/{scholarship}', [ProviderController::class, 'updateScholarship'])->name('scholarships.update');
        Route::post('/scholarships/{scholarship}/duplicate', [ProviderController::class, 'duplicateScholarship'])->name('scholarships.duplicate');
    });

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('password.email');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
