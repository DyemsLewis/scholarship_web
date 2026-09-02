<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicantDashboardController;
use App\Http\Controllers\ApplicationDocumentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\SupportReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/login', [PageController::class, 'login'])->name('login');
Route::get('/forgot-password', [PageController::class, 'forgotPassword'])->name('password.request');
Route::get('/reset-password', [PageController::class, 'resetPassword'])->name('password.reset');
Route::get('/register', [PageController::class, 'register'])->name('register');
Route::get('/provider/register', [PageController::class, 'providerRegister'])->name('provider.register');
Route::redirect('/terms', '/');
Route::post('/webhooks/paymongo', [BillingController::class, 'webhook'])->middleware('throttle:60,1')->name('webhooks.paymongo');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])->middleware(['auth', 'throttle:6,1'])->name('verification.send');
Route::get('/account/setup', [PageController::class, 'accountSetup'])->middleware('auth')->name('account.setup');
Route::get('/dashboard', [ApplicantDashboardController::class, 'index'])->middleware('auth')->name('dashboard');
Route::get('/dashboard/scholarships', [ApplicantDashboardController::class, 'scholarships'])->middleware('auth')->name('dashboard.scholarships');
Route::get('/dashboard/scholarships/data', [ApplicantDashboardController::class, 'scholarshipsData'])->middleware('auth')->name('dashboard.scholarships.data');
Route::get('/dashboard/scholarships/{scholarship}', [ApplicantDashboardController::class, 'scholarshipDetail'])->middleware('auth')->name('dashboard.scholarships.show');
Route::get('/dashboard/scholarships/{scholarship}/data', [ApplicantDashboardController::class, 'scholarshipDetailData'])->middleware('auth')->name('dashboard.scholarships.show.data');
Route::post('/dashboard/scholarships/{scholarship}/application-start', [ApplicantDashboardController::class, 'trackApplicationStart'])->middleware(['auth', 'throttle:30,1'])->name('dashboard.scholarships.application-start');
Route::get('/dashboard/applications', [ApplicantDashboardController::class, 'applications'])->middleware('auth')->name('dashboard.applications');
Route::get('/dashboard/documents', [ApplicantDashboardController::class, 'documents'])->middleware('auth')->name('dashboard.documents');
Route::get('/dashboard/profile', [ApplicantDashboardController::class, 'profile'])->middleware('auth')->name('dashboard.profile');
Route::get('/dashboard/reports', fn () => redirect('/dashboard'))->middleware('auth')->name('dashboard.reports');
Route::get('/dashboard/reports/data', [SupportReportController::class, 'applicantData'])->middleware('auth')->name('dashboard.reports.data');
Route::post('/dashboard/reports', [SupportReportController::class, 'store'])->middleware(['auth', 'throttle:6,1'])->name('dashboard.reports.store');
Route::get('/dashboard/profile/data', [ApplicantDashboardController::class, 'profileData'])->middleware('auth')->name('dashboard.profile.data');
Route::patch('/dashboard/profile', [ApplicantDashboardController::class, 'updateProfile'])->middleware('auth')->name('dashboard.profile.update');
Route::post('/dashboard/profile/photo', [ApplicantDashboardController::class, 'uploadProfilePhoto'])->middleware(['auth', 'throttle:10,1'])->name('dashboard.profile.photo.store');
Route::get('/dashboard/profile/photo', [ApplicantDashboardController::class, 'viewProfilePhoto'])->middleware('auth')->name('dashboard.profile.photo');
Route::delete('/dashboard/profile/photo', [ApplicantDashboardController::class, 'deleteProfilePhoto'])->middleware('auth')->name('dashboard.profile.photo.destroy');
Route::post('/dashboard/profile/verification-documents', [ApplicantDashboardController::class, 'uploadApplicantVerificationDocument'])->middleware(['auth', 'throttle:10,1'])->name('dashboard.applicant-verification-documents.store');
Route::get('/dashboard/profile/verification-documents/{document}/view', [ApplicantDashboardController::class, 'viewApplicantVerificationDocument'])->middleware('auth')->name('dashboard.applicant-verification-documents.view');
Route::delete('/dashboard/profile/verification-documents/{document}', [ApplicantDashboardController::class, 'deleteApplicantVerificationDocument'])->middleware('auth')->name('dashboard.applicant-verification-documents.destroy');
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
Route::patch('/dashboard/applications/{application}/withdraw', [ApplicantDashboardController::class, 'withdrawApplication'])->middleware(['auth', 'throttle:6,1'])->name('dashboard.applications.withdraw');
Route::patch('/dashboard/applications/{application}/correction-response', [ApplicantDashboardController::class, 'respondToCorrection'])->middleware(['auth', 'throttle:10,1'])->name('dashboard.applications.correction-response');
Route::patch('/dashboard/applications/{application}/schedules/{schedule}/acknowledge', [ApplicantDashboardController::class, 'acknowledgeApplicationSchedule'])->middleware('auth')->name('dashboard.applications.schedules.acknowledge');
Route::post('/dashboard/applications/{application}/documents', [ApplicantDashboardController::class, 'uploadDocument'])->middleware(['auth', 'throttle:20,1'])->name('dashboard.applications.documents.store');
Route::delete('/dashboard/documents/{document}', [ApplicantDashboardController::class, 'deleteDocument'])->middleware('auth')->name('dashboard.documents.destroy');
Route::post('/dashboard/scholarships/{scholarship}/save', [ApplicantDashboardController::class, 'saveScholarship'])->middleware('auth')->name('dashboard.scholarships.save');
Route::delete('/dashboard/scholarships/{scholarship}/save', [ApplicantDashboardController::class, 'unsaveScholarship'])->middleware('auth')->name('dashboard.scholarships.unsave');
Route::get('/documents/{document}/view', [ApplicationDocumentController::class, 'view'])->middleware('auth')->name('documents.view');
Route::get('/documents/{document}/download', [ApplicationDocumentController::class, 'download'])->middleware('auth')->name('documents.download');
Route::get('/service-files/{file}/view', [BillingController::class, 'viewServiceFile'])->middleware('auth')->name('service-files.view');
Route::get('/service-files/{file}/download', [BillingController::class, 'downloadServiceFile'])->middleware('auth')->name('service-files.download');
Route::get('/notifications', [NotificationController::class, 'index'])->middleware('auth')->name('notifications.index');
Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('auth')->name('notifications.read-all');
Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('auth')->name('notifications.read');
Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/dashboard/data', [AdminController::class, 'dashboardData'])->name('dashboard.data');
        Route::get('/manage-users', [AdminController::class, 'manageUsers'])->middleware('permission:manage_accounts')->name('manage-users');
        Route::get('/accounts/create', [AdminController::class, 'accountForm'])->middleware('permission:manage_accounts')->name('accounts.create');
        Route::get('/accounts/{user}/edit', [AdminController::class, 'accountForm'])->middleware('permission:manage_accounts')->name('accounts.edit');
        Route::get('/profile', [AdminController::class, 'profile'])->name('profile');
        Route::get('/reviews', [AdminController::class, 'reviews'])->middleware('permission:manage_reviews')->name('reviews');
        Route::get('/providers/{provider}/review', [AdminController::class, 'providerReview'])->middleware('permission:manage_reviews')->whereNumber('provider')->name('providers.review.show');
        Route::get('/providers/{provider}/review/data', [AdminController::class, 'providerReviewData'])->middleware('permission:manage_reviews')->whereNumber('provider')->name('providers.review.data');
        Route::get('/applicants/{applicant}/review', [AdminController::class, 'applicantReview'])->middleware('permission:manage_reviews')->whereNumber('applicant')->name('applicants.review.show');
        Route::get('/applicants/{applicant}/review/data', [AdminController::class, 'applicantReviewData'])->middleware('permission:manage_reviews')->whereNumber('applicant')->name('applicants.review.data');
        Route::get('/scholarships/{scholarship}/review', [AdminController::class, 'scholarshipReview'])->middleware('permission:manage_reviews')->name('scholarships.review.show');
        Route::get('/scholarships/{scholarship}/review/data', [AdminController::class, 'scholarshipReviewData'])->middleware('permission:manage_reviews')->name('scholarships.review.data');
        Route::get('/logs', [AdminController::class, 'logs'])->middleware('permission:view_logs')->name('logs');
        Route::get('/reports', [SupportReportController::class, 'adminPage'])->middleware('permission:manage_reports')->name('reports');
        Route::get('/reports/data', [SupportReportController::class, 'adminData'])->middleware('permission:manage_reports')->name('reports.data');
        Route::patch('/reports/{report}/status', [SupportReportController::class, 'updateStatus'])->middleware('permission:manage_reports')->name('reports.status');
        Route::get('/billing', [BillingController::class, 'adminPage'])->middleware('permission:manage_billing')->name('billing');
        Route::get('/billing/data', [BillingController::class, 'adminData'])->middleware('permission:manage_billing')->name('billing.data');
        Route::get('/billing/{purchase}', [BillingController::class, 'adminWorkspacePage'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.workspace');
        Route::get('/billing/{purchase}/data', [BillingController::class, 'adminWorkspaceData'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.workspace.data');
        Route::patch('/billing/{purchase}/fulfillment', [BillingController::class, 'updateFulfillment'])->middleware('permission:manage_billing')->name('billing.fulfillment');
        Route::patch('/billing/{purchase}/meeting', [BillingController::class, 'decideProviderMeeting'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.meeting.decide');
        Route::post('/billing/{purchase}/updates', [BillingController::class, 'storeAdminUpdate'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.updates.store');
        Route::post('/billing/{purchase}/deliverables', [BillingController::class, 'uploadAdminDeliverable'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.deliverables.store');
        Route::get('/users', [AdminController::class, 'users'])->middleware('permission:manage_accounts')->name('users');
        Route::post('/users', [AdminController::class, 'storeUser'])->middleware('permission:manage_accounts')->name('users.store');
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->middleware('permission:manage_accounts')->name('users.show');
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->middleware('permission:manage_accounts')->name('users.update');
        Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus'])->middleware('permission:manage_accounts')->name('users.status');
        Route::post('/users/{user}/force-password-reset', [AdminController::class, 'forcePasswordReset'])->middleware('permission:manage_accounts')->name('users.force-password-reset');
        Route::patch('/users/{user}/email-verification', [AdminController::class, 'verifyUserEmail'])->middleware('permission:manage_accounts')->name('users.email-verification');
        Route::post('/users/{user}/verification-email', [AdminController::class, 'resendUserVerificationEmail'])->middleware('permission:manage_accounts')->name('users.verification-email');
        Route::patch('/users/{applicant}/profile-verification', [AdminController::class, 'updateApplicantVerification'])->middleware('permission:manage_reviews')->name('users.profile-verification');
        Route::get('/applicant-verification-documents/{document}/view', [AdminController::class, 'viewApplicantVerificationDocument'])->middleware('permission:manage_reviews')->name('applicant-verification-documents.view');
        Route::get('/profile/data', [AdminController::class, 'profileData'])->name('profile.data');
        Route::patch('/profile', [AdminController::class, 'updateProfile'])->name('profile.update');
        Route::get('/reviews/data', [AdminController::class, 'reviewsData'])->middleware('permission:manage_reviews')->name('reviews.data');
        Route::patch('/providers/{provider}/verification', [AdminController::class, 'updateProviderVerification'])->middleware('permission:manage_reviews')->name('providers.verification');
        Route::patch('/scholarships/{scholarship}/review', [AdminController::class, 'updateScholarshipReview'])->middleware('permission:manage_reviews')->name('scholarships.review');
        Route::get('/provider-verification-documents/{document}/view', [AdminController::class, 'viewProviderVerificationDocument'])->middleware('permission:manage_reviews')->name('provider-verification-documents.view');
        Route::get('/provider-verification-documents/{document}/download', [AdminController::class, 'downloadProviderVerificationDocument'])->middleware('permission:manage_reviews')->name('provider-verification-documents.download');
        Route::get('/export/users', [AdminController::class, 'exportUsers'])->middleware('permission:export_data')->name('export.users');
        Route::get('/export/applications', [AdminController::class, 'exportApplications'])->middleware('permission:export_data')->name('export.applications');
        Route::get('/log-entries', [AdminController::class, 'logEntries'])->middleware('permission:view_logs')->name('log-entries');
    });

Route::middleware(['auth', 'provider'])
    ->prefix('provider')
    ->name('provider.')
    ->group(function (): void {
        Route::get('/', [ProviderController::class, 'index'])->name('index');
        Route::get('/dashboard/data', [ProviderController::class, 'dashboardData'])->name('dashboard.data');
        Route::get('/programs', [ProviderController::class, 'programs'])->name('programs');
        Route::redirect('/exams', '/provider/programs')->name('exams');
        Route::get('/programs/create', [ProviderController::class, 'programForm'])->middleware('permission:manage_programs')->name('programs.create');
        Route::get('/programs/{scholarship}', [ProviderController::class, 'programWorkspace'])->whereNumber('scholarship')->name('programs.show');
        Route::get('/programs/{scholarship}/edit', [ProviderController::class, 'programForm'])->middleware('permission:manage_programs')->name('programs.edit');
        Route::get('/programs/{scholarship}/applications', [ProviderController::class, 'programApplications'])->middleware(['permission:review_applications', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications');
        Route::get('/applications', [ProviderController::class, 'applications'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications');
        Route::get('/profile', [ProviderController::class, 'profile'])->name('profile');
        Route::get('/team', [ProviderController::class, 'team'])->middleware('permission:manage_team')->name('team');
        Route::get('/team/data', [ProviderController::class, 'teamData'])->middleware('permission:manage_team')->name('team.data');
        Route::get('/team/accounts/create', [ProviderController::class, 'teamAccountForm'])->middleware('permission:manage_team')->name('team.accounts.create');
        Route::get('/team/accounts/{account}/edit', [ProviderController::class, 'teamAccountForm'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.edit');
        Route::get('/team/accounts/{account}', [ProviderController::class, 'showTeamAccount'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.show');
        Route::post('/team/accounts', [ProviderController::class, 'storeTeamAccount'])->middleware('permission:manage_team')->name('team.accounts.store');
        Route::patch('/team/accounts/{account}', [ProviderController::class, 'updateTeamAccount'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.update');
        Route::patch('/team/accounts/{account}/status', [ProviderController::class, 'updateTeamAccountStatus'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.status');
        Route::get('/billing', [BillingController::class, 'providerPage'])->middleware(['permission:manage_billing', 'provider.approved'])->name('billing');
        Route::get('/billing/data', [BillingController::class, 'providerData'])->middleware(['permission:manage_billing', 'provider.approved'])->name('billing.data');
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware(['permission:manage_billing', 'provider.approved', 'throttle:5,1'])->name('billing.checkout');
        Route::post('/billing/sync', [BillingController::class, 'syncCheckout'])->middleware(['permission:manage_billing', 'provider.approved', 'throttle:10,1'])->name('billing.sync');
        Route::get('/billing/{purchase}', [BillingController::class, 'providerWorkspacePage'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace');
        Route::get('/billing/{purchase}/data', [BillingController::class, 'providerWorkspaceData'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace.data');
        Route::patch('/billing/{purchase}/request', [BillingController::class, 'updateProviderRequest'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.request.update');
        Route::post('/billing/{purchase}/meeting', [BillingController::class, 'requestProviderMeeting'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.meeting.request');
        Route::post('/billing/{purchase}/updates', [BillingController::class, 'storeProviderUpdate'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.updates.store');
        Route::post('/billing/{purchase}/files', [BillingController::class, 'uploadProviderFile'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.files.store');
        Route::post('/billing/{purchase}/confirm', [BillingController::class, 'confirmProviderCompletion'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.confirm');
        Route::post('/billing/{purchase}/reopen', [BillingController::class, 'reopenProviderService'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.reopen');
        Route::get('/reports', [SupportReportController::class, 'providerPage'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports');
        Route::get('/reports/data', [SupportReportController::class, 'providerData'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports.data');
        Route::patch('/reports/{report}/status', [SupportReportController::class, 'updateStatus'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports.status');
        Route::redirect('/insights', '/provider/applications?filter=pending_review')->name('insights.redirect');
        Route::redirect('/review', '/provider/applications?filter=pending_review')
            ->middleware(['permission:review_applications', 'provider.approved'])
            ->name('review');
        Route::get('/profile/data', [ProviderController::class, 'profileData'])->name('profile.data');
        Route::patch('/profile', [ProviderController::class, 'updateProfile'])->name('profile.update');
        Route::post('/verification-documents', [ProviderController::class, 'uploadVerificationDocument'])->middleware(['permission:manage_profile', 'throttle:10,1'])->name('verification-documents.store');
        Route::get('/verification-documents/{document}/view', [ProviderController::class, 'viewVerificationDocument'])->middleware('permission:manage_profile')->name('verification-documents.view');
        Route::get('/verification-documents/{document}/download', [ProviderController::class, 'downloadVerificationDocument'])->middleware('permission:manage_profile')->name('verification-documents.download');
        Route::delete('/verification-documents/{document}', [ProviderController::class, 'deleteVerificationDocument'])->middleware('permission:manage_profile')->name('verification-documents.destroy');
        Route::get('/insights/data', [ProviderController::class, 'insightsData'])->middleware(['permission:review_applications', 'provider.approved'])->name('insights.data');
        Route::get('/applications/data', [ProviderController::class, 'applicationsData'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.data');
        Route::get('/applications/{application}', [ProviderController::class, 'applicationDetail'])->middleware(['permission:review_applications', 'provider.approved'])->whereNumber('application')->name('applications.show');
        Route::get('/applications/{application}/data', [ProviderController::class, 'applicationDetailData'])->middleware(['permission:review_applications', 'provider.approved'])->whereNumber('application')->name('applications.show.data');
        Route::patch('/scholarships/{scholarship}/applications/bulk-advance', [ProviderController::class, 'bulkAdvanceApplications'])->middleware(['permission:review_applications', 'provider.approved', 'throttle:10,1'])->whereNumber('scholarship')->name('applications.bulk-advance');
        Route::patch('/applications/{application}/reviewer', [ProviderController::class, 'assignApplicationReviewer'])->middleware(['permission:review_applications', 'provider.approved'])->whereNumber('application')->name('applications.reviewer');
        Route::get('/applications/{application}/profile-proofs/{document}/view', [ProviderController::class, 'viewApplicantProfileProof'])
            ->whereNumber('application')
            ->whereNumber('document')
            ->middleware(['permission:review_applications', 'provider.approved'])
            ->name('applications.profile-proofs.view');
        Route::get('/applications/{application}/profile-photo', [ProviderController::class, 'viewApplicantProfilePhoto'])
            ->whereNumber('application')
            ->middleware(['permission:review_applications', 'provider.approved'])
            ->name('applications.profile-photo.view');
        Route::patch('/applications/{application}/profile-verification', [ProviderController::class, 'verifyApplicantProfile'])
            ->whereNumber('application')
            ->middleware(['permission:review_applications', 'provider.approved'])
            ->name('applications.profile-verification');
        Route::post('/applications/{application}/schedules', [ProviderController::class, 'upsertApplicationSchedule'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.schedules.upsert');
        Route::patch('/applications/{application}/schedules/{schedule}', [ProviderController::class, 'updateApplicationScheduleTracking'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.schedules.tracking');
        Route::patch('/applications/{application}/decision', [ProviderController::class, 'decideApplication'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.decision');
        Route::patch('/applications/{application}/stages/{stage}/result', [ProviderController::class, 'recordApplicationStageResult'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.stages.result');
        Route::patch('/applications/{application}/final-outcome', [ProviderController::class, 'recordApplicationFinalOutcome'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.final-outcome');
        Route::patch('/applications/{application}/correction', [ProviderController::class, 'handleApplicationCorrection'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.correction');
        Route::patch('/applications/{application}/waitlist', [ProviderController::class, 'handleApplicationWaitlist'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.waitlist');
        Route::patch('/applications/{application}/status', [ProviderController::class, 'updateApplicationStatus'])->middleware(['permission:review_applications', 'provider.approved'])->name('applications.status');
        Route::patch('/documents/{document}/status', [ProviderController::class, 'updateDocumentStatus'])->middleware(['permission:review_applications', 'provider.approved'])->name('documents.status');
        Route::get('/export/applications', [ProviderController::class, 'exportApplications'])->middleware(['permission:review_applications', 'provider.approved'])->name('export.applications');
        Route::get('/scholarships', [ProviderController::class, 'scholarships'])->name('scholarships');
        Route::post('/scholarships', [ProviderController::class, 'storeScholarship'])->middleware('permission:manage_programs')->name('scholarships.store');
        Route::post('/scholarships/{scholarship}/events', [ProviderController::class, 'upsertScholarshipEvent'])->middleware(['permission:manage_programs', 'provider.approved'])->name('scholarships.events.upsert');
        Route::post('/scholarships/{scholarship}/announcements', [ProviderController::class, 'storeScholarshipAnnouncement'])->middleware(['permission:review_applications', 'provider.approved'])->name('scholarships.announcements.store');
        Route::patch('/scholarships/{scholarship}/events/{event}/complete', [ProviderController::class, 'completeScholarshipEvent'])->middleware(['permission:review_applications', 'provider.approved'])->name('scholarships.events.complete');
        Route::patch('/scholarships/{scholarship}/events/{event}/attendance', [ProviderController::class, 'bulkUpdateScholarshipEventAttendance'])->middleware(['permission:review_applications', 'provider.approved'])->name('scholarships.events.attendance');
        Route::get('/scholarships/{scholarship}', [ProviderController::class, 'showScholarship'])->name('scholarships.show');
        Route::put('/scholarships/{scholarship}', [ProviderController::class, 'updateScholarship'])->middleware('permission:manage_programs')->name('scholarships.update');
        Route::post('/scholarships/{scholarship}/duplicate', [ProviderController::class, 'duplicateScholarship'])->middleware('permission:manage_programs')->name('scholarships.duplicate');
    });

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('password.email');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
Route::post('/register/verify', [AuthController::class, 'verifyRegistrationCode'])->middleware('throttle:10,1')->name('register.verify');
Route::post('/register/resend-code', [AuthController::class, 'resendRegistrationCode'])->middleware('throttle:3,10')->name('register.resend-code');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
