<?php

use App\Http\Controllers\Admin\AccountManagerWorkspaceController;
use App\Http\Controllers\Admin\BillingOfficerWorkspaceController;
use App\Http\Controllers\Admin\FinanceOfficerWorkspaceController;
use App\Http\Controllers\Admin\RecordsOfficerWorkspaceController;
use App\Http\Controllers\Admin\ReviewOfficerWorkspaceController;
use App\Http\Controllers\Admin\SuperAdminWorkspaceController;
use App\Http\Controllers\Admin\SupportOfficerWorkspaceController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApplicantDashboardController;
use App\Http\Controllers\ApplicationDocumentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PortalManagerController;
use App\Http\Controllers\Provider\ApplicationStageOperationController;
use App\Http\Controllers\Provider\Programs\ProgramCoordinatorDashboardController;
use App\Http\Controllers\Provider\Programs\ProgramDirectoryController;
use App\Http\Controllers\Provider\Programs\ProgramRecordController;
use App\Http\Controllers\Provider\RoleDashboardController;
use App\Http\Controllers\Provider\SelectionSessionWorkspaceController;
use App\Http\Controllers\ProviderApplicationPageController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderProgramPageController;
use App\Http\Controllers\ProviderRecipientPageController;
use App\Http\Controllers\ProviderWorkspacePageController;
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
Route::get('/account/setup/data', [AuthController::class, 'accountSetupData'])->middleware('auth')->name('account.setup.data');
Route::post('/account/setup/password', [AuthController::class, 'completeAccountSetup'])->middleware(['auth', 'throttle:6,1'])->name('account.setup.password');
Route::get('/dashboard', [ApplicantDashboardController::class, 'index'])->middleware('auth')->name('dashboard');
Route::get('/dashboard/scholarships', [ApplicantDashboardController::class, 'scholarships'])->middleware('auth')->name('dashboard.scholarships');
Route::get('/dashboard/scholarships/data', [ApplicantDashboardController::class, 'scholarshipsData'])->middleware('auth')->name('dashboard.scholarships.data');
Route::get('/dashboard/providers', [ApplicantDashboardController::class, 'providers'])->middleware('auth')->name('dashboard.providers');
Route::get('/dashboard/providers/data', [ApplicantDashboardController::class, 'providersData'])->middleware('auth')->name('dashboard.providers.data');
Route::get('/dashboard/providers/{provider}', [ApplicantDashboardController::class, 'providerDetail'])->middleware('auth')->whereNumber('provider')->name('dashboard.providers.show');
Route::get('/dashboard/providers/{provider}/data', [ApplicantDashboardController::class, 'providerDetailData'])->middleware('auth')->whereNumber('provider')->name('dashboard.providers.show.data');
Route::get('/dashboard/scholarships/{scholarship}', [ApplicantDashboardController::class, 'scholarshipDetail'])->middleware('auth')->name('dashboard.scholarships.show');
Route::get('/dashboard/scholarships/{scholarship}/data', [ApplicantDashboardController::class, 'scholarshipDetailData'])->middleware('auth')->name('dashboard.scholarships.show.data');
Route::post('/dashboard/scholarships/{scholarship}/application-start', [ApplicantDashboardController::class, 'trackApplicationStart'])->middleware(['auth', 'throttle:30,1'])->name('dashboard.scholarships.application-start');
Route::get('/dashboard/applications', [ApplicantDashboardController::class, 'applications'])->middleware('auth')->name('dashboard.applications');
Route::get('/dashboard/monitoring', [ApplicantDashboardController::class, 'monitoring'])->middleware('auth')->name('dashboard.monitoring');
Route::get('/dashboard/monitoring/{application}', [ApplicantDashboardController::class, 'monitoringDetail'])->middleware('auth')->whereNumber('application')->name('dashboard.monitoring.show');
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
Route::post('/dashboard/profile/verification-documents/{document}/scan', [ApplicantDashboardController::class, 'rescanApplicantVerificationDocument'])->middleware(['auth', 'throttle:6,1'])->name('dashboard.applicant-verification-documents.scan');
Route::get('/dashboard/profile/verification-documents/{document}/view', [ApplicantDashboardController::class, 'viewApplicantVerificationDocument'])->middleware('auth')->name('dashboard.applicant-verification-documents.view');
Route::delete('/dashboard/profile/verification-documents/{document}', [ApplicantDashboardController::class, 'deleteApplicantVerificationDocument'])->middleware('auth')->name('dashboard.applicant-verification-documents.destroy');
Route::get('/dashboard/data', [ApplicantDashboardController::class, 'data'])->middleware('auth')->name('dashboard.data');
Route::get('/dashboard/overview-data', [ApplicantDashboardController::class, 'overviewData'])->middleware('auth')->name('dashboard.overview.data');
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
Route::post('/dashboard/applications/{application}/monitoring-cycles/{cycle}/grade-record', [ApplicantDashboardController::class, 'uploadRecipientMonitoringGrade'])->middleware(['auth', 'throttle:6,1'])->name('dashboard.applications.monitoring-grade.store');
Route::patch('/dashboard/applications/{application}/monitoring-cycles/{cycle}/manual-grade', [ApplicantDashboardController::class, 'saveRecipientMonitoringManualGrade'])->middleware(['auth', 'throttle:10,1'])->name('dashboard.applications.monitoring-grade.manual');
Route::post('/dashboard/applications/{application}/monitoring-requirements/{requirement}/submission', [ApplicantDashboardController::class, 'uploadRecipientMonitoringRequirement'])->middleware(['auth', 'throttle:10,1'])->whereNumber(['application', 'requirement'])->name('dashboard.applications.monitoring-requirements.store');
Route::patch('/dashboard/applications/{application}/monitoring-requirements/{requirement}/manual-grade', [ApplicantDashboardController::class, 'saveRecipientMonitoringRequirementManualGrade'])->middleware(['auth', 'throttle:10,1'])->whereNumber(['application', 'requirement'])->name('dashboard.applications.monitoring-requirements.manual-grade');
Route::post('/dashboard/applications/{application}/monitoring-requirements/{requirement}/adjustment-request', [ApplicantDashboardController::class, 'storeRecipientMonitoringAdjustmentRequest'])->middleware(['auth', 'throttle:10,1'])->whereNumber(['application', 'requirement'])->name('dashboard.applications.monitoring-requirements.adjustment-request');
Route::get('/dashboard/monitoring-adjustment-requests/{adjustment}/attachment', [ApplicantDashboardController::class, 'viewRecipientMonitoringAdjustmentAttachment'])->middleware('auth')->whereNumber('adjustment')->name('dashboard.monitoring-adjustment-requests.attachment');
Route::get('/dashboard/monitoring-submissions/{submission}/view', [ApplicantDashboardController::class, 'viewRecipientMonitoringGrade'])->middleware('auth')->name('dashboard.monitoring-submissions.view');
Route::post('/dashboard/benefit-release-records/{record}/response', [ApplicantDashboardController::class, 'respondToRecipientBenefitRelease'])->middleware(['auth', 'throttle:10,1'])->whereNumber('record')->name('dashboard.benefit-release-records.response');
Route::get('/dashboard/benefit-release-records/{record}/receipt', [ApplicantDashboardController::class, 'viewRecipientBenefitReleaseReceipt'])->middleware('auth')->whereNumber('record')->name('dashboard.benefit-release-records.receipt');
Route::get('/dashboard/benefit-receipt-responses/{response}/files/{kind}', [ApplicantDashboardController::class, 'viewRecipientBenefitReceiptResponseFile'])->middleware('auth')->whereNumber('response')->whereIn('kind', ['evidence', 'resolution-proof'])->name('dashboard.benefit-receipt-responses.file');
Route::post('/dashboard/support-decisions/{decision}/response', [ApplicantDashboardController::class, 'respondToRecipientSupportDecision'])->middleware(['auth', 'throttle:10,1'])->whereNumber('decision')->name('dashboard.support-decisions.response');
Route::get('/dashboard/support-decisions/{decision}/files/{kind}', [ApplicantDashboardController::class, 'viewRecipientSupportDecisionFile'])->middleware('auth')->whereNumber('decision')->whereIn('kind', ['decision-document', 'applicant-response', 'resolution-proof'])->name('dashboard.support-decisions.file');
Route::patch('/dashboard/applications/{application}/withdraw', [ApplicantDashboardController::class, 'withdrawApplication'])->middleware(['auth', 'throttle:6,1'])->name('dashboard.applications.withdraw');
Route::patch('/dashboard/applications/{application}/correction-response', [ApplicantDashboardController::class, 'respondToCorrection'])->middleware(['auth', 'throttle:10,1'])->name('dashboard.applications.correction-response');
Route::patch('/dashboard/applications/{application}/schedules/{schedule}/acknowledge', [ApplicantDashboardController::class, 'acknowledgeApplicationSchedule'])->middleware('auth')->name('dashboard.applications.schedules.acknowledge');
Route::patch('/dashboard/applications/{application}/stage-activities/{assignment}/acknowledge', [ApplicantDashboardController::class, 'acknowledgeStageActivity'])->middleware(['auth', 'throttle:10,1'])->whereNumber(['application', 'assignment'])->name('dashboard.applications.stage-activities.acknowledge');
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
        Route::get('/', [SuperAdminWorkspaceController::class, 'index'])->name('index');
        Route::get('/dashboard/data', [SuperAdminWorkspaceController::class, 'data'])->name('dashboard.data');
        Route::get('/governance/team', [SuperAdminWorkspaceController::class, 'team'])->name('governance.team');
        Route::get('/governance/team/data', [SuperAdminWorkspaceController::class, 'teamData'])->name('governance.team.data');
        Route::get('/governance/platform', [SuperAdminWorkspaceController::class, 'platform'])->name('governance.platform');
        Route::get('/governance/platform/data', [SuperAdminWorkspaceController::class, 'platformData'])->name('governance.platform.data');
        Route::middleware('permission:manage_accounts')
            ->prefix('workspaces/accounts')
            ->group(function (): void {
                Route::get('/', [AccountManagerWorkspaceController::class, 'dashboard'])->name('workspaces.accounts');
                Route::get('/dashboard/data', [AccountManagerWorkspaceController::class, 'dashboardData'])->name('workspaces.accounts.dashboard.data');
                Route::get('/directory', [AccountManagerWorkspaceController::class, 'directory'])->name('workspaces.accounts.directory');
                Route::get('/data', [AccountManagerWorkspaceController::class, 'data'])->name('workspaces.accounts.data');
                Route::get('/attention', [AccountManagerWorkspaceController::class, 'attention'])->name('workspaces.accounts.attention');
                Route::get('/create', [AccountManagerWorkspaceController::class, 'create'])->name('workspaces.accounts.create');
                Route::get('/{user}/photo', [AccountManagerWorkspaceController::class, 'photo'])->whereNumber('user')->name('workspaces.accounts.photo');
                Route::get('/{user}', [AccountManagerWorkspaceController::class, 'show'])->whereNumber('user')->name('workspaces.accounts.show');
                Route::get('/{user}/data', [AccountManagerWorkspaceController::class, 'showData'])->whereNumber('user')->name('workspaces.accounts.show.data');
            });
        Route::middleware('permission:manage_reviews')
            ->prefix('workspaces/reviews')
            ->group(function (): void {
                Route::get('/', [ReviewOfficerWorkspaceController::class, 'index'])->name('workspaces.reviews');
                Route::get('/dashboard/data', [ReviewOfficerWorkspaceController::class, 'dashboardData'])->name('workspaces.reviews.dashboard.data');
                Route::get('/summary', [ReviewOfficerWorkspaceController::class, 'summary'])->name('workspaces.reviews.summary');
                Route::get('/providers', [ReviewOfficerWorkspaceController::class, 'providers'])->name('workspaces.reviews.providers');
                Route::get('/providers/data', [ReviewOfficerWorkspaceController::class, 'providerData'])->name('workspaces.reviews.providers.data');
                Route::get('/providers/{provider}', [ReviewOfficerWorkspaceController::class, 'providerReview'])->whereNumber('provider')->name('workspaces.reviews.providers.show');
                Route::get('/programs', [ReviewOfficerWorkspaceController::class, 'programs'])->name('workspaces.reviews.programs');
                Route::get('/programs/data', [ReviewOfficerWorkspaceController::class, 'programData'])->name('workspaces.reviews.programs.data');
                Route::get('/programs/{scholarship}', [ReviewOfficerWorkspaceController::class, 'programReview'])->whereNumber('scholarship')->name('workspaces.reviews.programs.show');
                Route::get('/applicants', [ReviewOfficerWorkspaceController::class, 'applicants'])->name('workspaces.reviews.applicants');
                Route::get('/applicants/data', [ReviewOfficerWorkspaceController::class, 'applicantData'])->name('workspaces.reviews.applicants.data');
                Route::get('/applicants/{applicant}', [ReviewOfficerWorkspaceController::class, 'applicantReview'])->whereNumber('applicant')->name('workspaces.reviews.applicants.show');
                Route::get('/benefits', [ReviewOfficerWorkspaceController::class, 'benefits'])->name('workspaces.reviews.benefits');
                Route::get('/benefits/data', [ReviewOfficerWorkspaceController::class, 'benefitData'])->name('workspaces.reviews.benefits.data');
                Route::get('/monitoring', [ReviewOfficerWorkspaceController::class, 'monitoring'])->name('workspaces.reviews.monitoring');
                Route::get('/monitoring/data', [ReviewOfficerWorkspaceController::class, 'monitoringData'])->name('workspaces.reviews.monitoring.data');
                Route::get('/monitoring/{application}', [ReviewOfficerWorkspaceController::class, 'monitoringReview'])->whereNumber('application')->name('workspaces.reviews.monitoring.show');
                Route::get('/selection-activities', [ReviewOfficerWorkspaceController::class, 'selectionActivities'])->name('workspaces.reviews.selection-activities');
                Route::get('/selection-activities/data', [ReviewOfficerWorkspaceController::class, 'selectionActivityData'])->name('workspaces.reviews.selection-activities.data');
                Route::get('/selection-activities/evidence/{evidence}/view', [ReviewOfficerWorkspaceController::class, 'selectionActivityEvidence'])->whereNumber('evidence')->name('workspaces.reviews.selection-activities.evidence.view');
                Route::get('/selection-activities/{session}', [ReviewOfficerWorkspaceController::class, 'selectionActivity'])->whereNumber('session')->name('workspaces.reviews.selection-activities.show');
                Route::get('/selection-activities/{session}/data', [ReviewOfficerWorkspaceController::class, 'selectionActivityRecordData'])->whereNumber('session')->name('workspaces.reviews.selection-activities.show.data');
            });
        Route::middleware('permission:manage_reports')
            ->prefix('workspaces/support')
            ->group(function (): void {
                Route::get('/', [SupportOfficerWorkspaceController::class, 'index'])->name('workspaces.support');
                Route::get('/dashboard/data', [SupportOfficerWorkspaceController::class, 'dashboardData'])->name('workspaces.support.dashboard.data');
                Route::get('/summary', [SupportOfficerWorkspaceController::class, 'summary'])->name('workspaces.support.summary');
                Route::get('/data', [SupportOfficerWorkspaceController::class, 'data'])->name('workspaces.support.data');
                Route::get('/cases/{report}', [SupportOfficerWorkspaceController::class, 'show'])->whereNumber('report')->name('workspaces.support.cases.show');
                Route::get('/cases/{report}/data', [SupportOfficerWorkspaceController::class, 'showData'])->whereNumber('report')->name('workspaces.support.cases.data');
                Route::get('/reporters/{reporter}/photo', [SupportOfficerWorkspaceController::class, 'reporterPhoto'])->whereNumber('reporter')->name('workspaces.support.reporters.photo');
                Route::get('/{queue}', [SupportOfficerWorkspaceController::class, 'queue'])
                    ->whereIn('queue', ['needs-response', 'waiting', 'resolved'])
                    ->name('workspaces.support.queue');
            });
        Route::middleware('permission:manage_billing')
            ->prefix('workspaces/billing')
            ->group(function (): void {
                Route::get('/', [BillingOfficerWorkspaceController::class, 'index'])->name('workspaces.billing');
                Route::get('/dashboard/data', [BillingOfficerWorkspaceController::class, 'dashboardData'])->name('workspaces.billing.dashboard.data');
                Route::get('/summary', [BillingOfficerWorkspaceController::class, 'summary'])->name('workspaces.billing.summary');
                Route::get('/queue-data', [BillingOfficerWorkspaceController::class, 'data'])->name('workspaces.billing.queue-data');
                Route::get('/queues/{queue}', [BillingOfficerWorkspaceController::class, 'queue'])
                    ->whereIn('queue', ['ready', 'active', 'waiting', 'completed'])
                    ->name('workspaces.billing.queue');
                Route::get('/requests/{purchase}', [BillingOfficerWorkspaceController::class, 'show'])->whereNumber('purchase')->name('workspaces.billing.requests.show');
                Route::get('/requests/{purchase}/{section}', [BillingOfficerWorkspaceController::class, 'show'])
                    ->whereNumber('purchase')
                    ->whereIn('section', ['delivery', 'files', 'updates', 'meeting'])
                    ->name('workspaces.billing.requests.section');
            });
        Route::get('/workspaces/billing/data', [BillingController::class, 'billingOfficerWorkspaceData'])->middleware('permission:manage_billing')->name('workspaces.billing.data');
        Route::middleware('permission:view_finance')
            ->prefix('workspaces/finance')
            ->group(function (): void {
                Route::get('/', [FinanceOfficerWorkspaceController::class, 'index'])->name('workspaces.finance');
                Route::get('/dashboard/data', [FinanceOfficerWorkspaceController::class, 'dashboardData'])->name('workspaces.finance.dashboard.data');
                Route::get('/summary', [FinanceOfficerWorkspaceController::class, 'summary'])->name('workspaces.finance.summary');
                Route::get('/payments', [FinanceOfficerWorkspaceController::class, 'payments'])->name('workspaces.finance.payments');
                Route::get('/payments/data', [FinanceOfficerWorkspaceController::class, 'paymentData'])->name('workspaces.finance.payments.data');
                Route::get('/exceptions', [FinanceOfficerWorkspaceController::class, 'exceptions'])->name('workspaces.finance.exceptions');
                Route::get('/exceptions/data', [FinanceOfficerWorkspaceController::class, 'exceptionData'])->name('workspaces.finance.exceptions.data');
                Route::get('/receipts', [FinanceOfficerWorkspaceController::class, 'legacyReceipts'])->name('workspaces.finance.receipts');
                Route::get('/receipts/{purchase}', [FinanceOfficerWorkspaceController::class, 'receipt'])->whereNumber('purchase')->name('workspaces.finance.receipts.show');
                Route::get('/receipts/{purchase}/data', [FinanceOfficerWorkspaceController::class, 'receiptData'])->whereNumber('purchase')->name('workspaces.finance.receipts.data');
            });
        Route::get('/workspaces/finance/data', [FinanceController::class, 'financeOfficerData'])->middleware('permission:view_finance')->name('workspaces.finance.data');
        Route::prefix('workspaces/records')
            ->group(function (): void {
                Route::get('/', [RecordsOfficerWorkspaceController::class, 'dashboard'])->middleware('permission:view_logs')->name('workspaces.records');
                Route::get('/dashboard/data', [RecordsOfficerWorkspaceController::class, 'dashboardData'])->middleware('permission:view_logs')->name('workspaces.records.dashboard.data');
                Route::get('/activity', [RecordsOfficerWorkspaceController::class, 'activity'])->middleware('permission:view_logs')->name('workspaces.records.activity');
                Route::get('/activity/data', [RecordsOfficerWorkspaceController::class, 'activityData'])->middleware('permission:view_logs')->name('workspaces.records.activity.data');
                Route::get('/activity/{activityLog}', [RecordsOfficerWorkspaceController::class, 'show'])->middleware('permission:view_logs')->whereNumber('activityLog')->name('workspaces.records.activity.show');
                Route::get('/activity/{activityLog}/data', [RecordsOfficerWorkspaceController::class, 'showData'])->middleware('permission:view_logs')->whereNumber('activityLog')->name('workspaces.records.activity.show.data');
                Route::get('/exports', [RecordsOfficerWorkspaceController::class, 'exports'])->middleware('permission:export_data')->name('workspaces.records.exports');
                Route::get('/exports/data', [RecordsOfficerWorkspaceController::class, 'exportData'])->middleware('permission:export_data')->name('workspaces.records.exports.data');
            });
        Route::middleware('portal.manager')
            ->prefix('workspaces/portal')
            ->name('workspaces.portal.')
            ->group(function (): void {
                Route::get('/', [PortalManagerController::class, 'index'])->name('index');
                Route::get('/data', [PortalManagerController::class, 'data'])->name('data');
                Route::get('/accounts', [PortalManagerController::class, 'accounts'])->name('accounts');
                Route::get('/accounts/data', [PortalManagerController::class, 'accountData'])->name('accounts.data');
                Route::get('/reviews', [PortalManagerController::class, 'reviews'])->name('reviews');
                Route::get('/reviews/data', [PortalManagerController::class, 'reviewData'])->name('reviews.data');
                Route::get('/reviews/providers/{provider}', [PortalManagerController::class, 'providerReview'])->whereNumber('provider')->name('reviews.providers.show');
                Route::get('/reviews/programs/{scholarship}', [PortalManagerController::class, 'programReview'])->whereNumber('scholarship')->name('reviews.programs.show');
                Route::get('/reviews/applicants/{applicant}', [PortalManagerController::class, 'applicantReview'])->whereNumber('applicant')->name('reviews.applicants.show');
                Route::get('/reviews/monitoring/{application}', [PortalManagerController::class, 'monitoringReview'])->whereNumber('application')->name('reviews.monitoring.show');
                Route::get('/support', [PortalManagerController::class, 'support'])->name('support');
                Route::get('/support/data', [PortalManagerController::class, 'supportData'])->name('support.data');
                Route::get('/billing', [PortalManagerController::class, 'billing'])->name('billing');
                Route::get('/billing/data', [PortalManagerController::class, 'billingData'])->name('billing.data');
                Route::get('/finance', [PortalManagerController::class, 'finance'])->name('finance');
                Route::get('/finance/data', [PortalManagerController::class, 'financeData'])->name('finance.data');
                Route::get('/finance/receipts', [PortalManagerController::class, 'finance'])->name('finance.receipts');
                Route::get('/records/activity', [PortalManagerController::class, 'records'])->name('records.activity');
                Route::get('/records/data', [PortalManagerController::class, 'recordsData'])->name('records.data');
                Route::get('/records/exports', [PortalManagerController::class, 'records'])->name('records.exports');
            });
        Route::get('/manage-users', [AdminController::class, 'manageUsers'])->middleware('permission:manage_accounts')->name('manage-users');
        Route::get('/accounts/create', [AdminController::class, 'accountForm'])->middleware('permission:manage_accounts')->name('accounts.create');
        Route::get('/accounts/{user}/edit', [AdminController::class, 'accountForm'])->middleware('permission:manage_accounts')->name('accounts.edit');
        Route::get('/profile', [AdminController::class, 'profile'])->name('profile');
        Route::get('/reviews', [AdminController::class, 'reviews'])->middleware('permission:manage_reviews')->name('reviews');
        Route::get('/providers/{provider}/review', [AdminController::class, 'providerReview'])->middleware('permission:manage_reviews')->whereNumber('provider')->name('providers.review.show');
        Route::get('/providers/{provider}/review/data', [AdminController::class, 'providerReviewData'])->middleware('permission:manage_reviews')->whereNumber('provider')->name('providers.review.data');
        Route::get('/applicants/{applicant}/review', [AdminController::class, 'applicantReview'])->middleware('permission:manage_reviews')->whereNumber('applicant')->name('applicants.review.show');
        Route::get('/applicants/{applicant}/review/data', [AdminController::class, 'applicantReviewData'])->middleware('permission:manage_reviews')->whereNumber('applicant')->name('applicants.review.data');
        Route::get('/applicants/{applicant}/profile-photo', [AdminController::class, 'viewApplicantProfilePhoto'])->middleware('permission:manage_reviews')->whereNumber('applicant')->name('applicants.profile-photo.view');
        Route::patch('/applicants/{applicant}/profile-photo-review', [AdminController::class, 'reviewApplicantProfilePhoto'])->middleware('permission:manage_reviews')->whereNumber('applicant')->name('applicants.profile-photo-review');
        Route::get('/scholarships/{scholarship}/review', [AdminController::class, 'scholarshipReview'])->middleware('permission:manage_reviews')->name('scholarships.review.show');
        Route::get('/scholarships/{scholarship}/review/data', [AdminController::class, 'scholarshipReviewData'])->middleware('permission:manage_reviews')->name('scholarships.review.data');
        Route::get('/logs', [AdminController::class, 'logs'])->middleware('permission:view_logs')->name('logs');
        Route::get('/reports', [SupportReportController::class, 'adminPage'])->middleware('permission:manage_reports')->name('reports');
        Route::get('/reports/data', [SupportReportController::class, 'adminData'])->middleware('permission:manage_reports')->name('reports.data');
        Route::get('/reports/{report}/attachment', [SupportReportController::class, 'viewAttachment'])->middleware('permission:manage_reports')->name('reports.attachment');
        Route::patch('/reports/{report}/status', [SupportReportController::class, 'updateStatus'])->middleware('permission:manage_reports')->name('reports.status');
        Route::get('/billing', [BillingController::class, 'adminPage'])->middleware('permission:manage_billing')->name('billing');
        Route::get('/billing/data', [BillingController::class, 'adminData'])->middleware('permission:manage_billing')->name('billing.data');
        Route::get('/billing/{purchase}', [BillingController::class, 'adminWorkspacePage'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.workspace');
        Route::get('/billing/{purchase}/data', [BillingController::class, 'adminWorkspaceData'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.workspace.data');
        Route::patch('/billing/{purchase}/fulfillment', [BillingController::class, 'updateFulfillment'])->middleware('permission:manage_billing')->name('billing.fulfillment');
        Route::patch('/billing/{purchase}/meeting', [BillingController::class, 'decideProviderMeeting'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.meeting.decide');
        Route::post('/billing/{purchase}/updates', [BillingController::class, 'storeAdminUpdate'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.updates.store');
        Route::post('/billing/{purchase}/deliverables', [BillingController::class, 'uploadAdminDeliverable'])->middleware('permission:manage_billing')->whereNumber('purchase')->name('billing.deliverables.store');
        Route::get('/finance', [FinanceController::class, 'page'])->middleware('permission:view_finance')->name('finance');
        Route::get('/finance/data', [FinanceController::class, 'data'])->middleware('permission:view_finance')->name('finance.data');
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
        Route::get('/benefit-release-records/{record}/receipt', [AdminController::class, 'viewBenefitReleaseReceipt'])->middleware('permission:manage_reviews')->whereNumber('record')->name('benefit-release-records.receipt');
        Route::get('/monitoring/{application}', [AdminController::class, 'monitoringReview'])->middleware('permission:manage_reviews')->whereNumber('application')->name('monitoring.show');
        Route::get('/monitoring/{application}/data', [AdminController::class, 'monitoringReviewData'])->middleware('permission:manage_reviews')->whereNumber('application')->name('monitoring.data');
        Route::post('/monitoring/{application}/oversight-reviews', [AdminController::class, 'storeMonitoringOversightReview'])->middleware(['permission:manage_reviews', 'throttle:20,1'])->whereNumber('application')->name('monitoring.oversight-reviews.store');
        Route::get('/monitoring-submissions/{submission}/view', [AdminController::class, 'viewMonitoringSubmission'])->middleware('permission:manage_reviews')->whereNumber('submission')->name('monitoring-submissions.view');
        Route::get('/monitoring-adjustments/{adjustment}/attachment', [AdminController::class, 'viewMonitoringAdjustmentAttachment'])->middleware('permission:manage_reviews')->whereNumber('adjustment')->name('monitoring-adjustments.attachment');
        Route::get('/benefit-receipt-responses/{response}/files/{kind}', [AdminController::class, 'viewBenefitReceiptResponseFile'])->middleware('permission:manage_reviews')->whereNumber('response')->whereIn('kind', ['recipient-evidence', 'resolution-proof'])->name('benefit-receipt-responses.file');
        Route::get('/support-decisions/{decision}/files/{kind}', [AdminController::class, 'viewSupportDecisionFile'])->middleware('permission:manage_reviews')->whereNumber('decision')->whereIn('kind', ['decision-document', 'applicant-response', 'resolution-proof'])->name('support-decisions.file');
        Route::get('/profile/data', [AdminController::class, 'profileData'])->name('profile.data');
        Route::patch('/profile', [AdminController::class, 'updateProfile'])->name('profile.update');
        Route::get('/reviews/data', [AdminController::class, 'reviewsData'])->middleware('permission:manage_reviews')->name('reviews.data');
        Route::patch('/providers/{provider}/verification', [AdminController::class, 'updateProviderVerification'])->middleware('permission:manage_reviews')->name('providers.verification');
        Route::patch('/scholarships/{scholarship}/review', [AdminController::class, 'updateScholarshipReview'])->middleware('permission:manage_reviews')->name('scholarships.review');
        Route::get('/provider-verification-documents/{document}/view', [AdminController::class, 'viewProviderVerificationDocument'])->middleware('permission:manage_reviews')->name('provider-verification-documents.view');
        Route::get('/provider-verification-documents/{document}/download', [AdminController::class, 'downloadProviderVerificationDocument'])->middleware('permission:manage_reviews')->name('provider-verification-documents.download');
        Route::get('/export/users', [AdminController::class, 'exportUsers'])->middleware('permission:export_data')->name('export.users');
        Route::get('/export/applications', [AdminController::class, 'exportApplications'])->middleware('permission:export_data')->name('export.applications');
        Route::get('/export/monitoring', [AdminController::class, 'exportMonitoring'])->middleware('permission:export_data')->name('export.monitoring');
        Route::get('/log-entries', [AdminController::class, 'logEntries'])->middleware('permission:view_logs')->name('log-entries');
    });

Route::middleware(['auth', 'provider'])
    ->prefix('provider')
    ->name('provider.')
    ->group(function (): void {
        Route::get('/', [ProviderWorkspacePageController::class, 'index'])->name('index');
        Route::get('/governance', [ProviderWorkspacePageController::class, 'governance'])->name('governance');
        Route::get('/governance/data', [ProviderController::class, 'governanceData'])->name('governance.data');
        Route::patch('/governance/operating-mode', [ProviderController::class, 'updateOperatingMode'])
            ->middleware('throttle:5,1')
            ->name('governance.operating-mode');
        Route::get('/workspaces/programs', [ProgramDirectoryController::class, 'redirectLegacy'])
            ->defaults('programFilter', 'all')
            ->middleware('permission:manage_programs')
            ->name('workspaces.programs');
        Route::get('/workspaces/programs/data', [ProviderController::class, 'programCoordinatorWorkspaceData'])
            ->middleware('permission:manage_programs')
            ->name('workspaces.programs.data');
        Route::get('/workspaces/programs/drafts', [ProgramDirectoryController::class, 'redirectLegacy'])
            ->defaults('programFilter', 'drafts')
            ->middleware('permission:manage_programs')
            ->name('workspaces.programs.drafts');
        Route::get('/workspaces/programs/review', [ProgramDirectoryController::class, 'redirectLegacy'])
            ->defaults('programFilter', 'review')
            ->middleware('permission:manage_programs')
            ->name('workspaces.programs.review');
        Route::get('/workspaces/programs/published', [ProgramDirectoryController::class, 'redirectLegacy'])
            ->defaults('programFilter', 'published')
            ->middleware('permission:manage_programs')
            ->name('workspaces.programs.published');
        Route::get('/workspaces/programs/closed', [ProgramDirectoryController::class, 'redirectLegacy'])
            ->defaults('programFilter', 'closed')
            ->middleware('permission:manage_programs')
            ->name('workspaces.programs.closed');
        Route::get('/workspaces/reviews', [ProviderWorkspacePageController::class, 'reviewDashboard'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews');
        Route::get('/workspaces/reviews/dashboard/data', [RoleDashboardController::class, 'reviewer'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews.dashboard.data');
        Route::get('/workspaces/reviews/assigned', [ProviderWorkspacePageController::class, 'reviews'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews.assigned');
        Route::get('/workspaces/reviews/unassigned', [ProviderWorkspacePageController::class, 'reviews'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews.unassigned');
        Route::get('/workspaces/reviews/returned', [ProviderWorkspacePageController::class, 'reviews'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews.returned');
        Route::get('/workspaces/reviews/history', [ProviderWorkspacePageController::class, 'reviews'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews.history');
        Route::get('/workspaces/reviews/data', [ProviderController::class, 'applicationReviewerWorkspaceData'])
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('workspaces.reviews.data');
        Route::get('/workspaces/selection', [ProviderWorkspacePageController::class, 'selectionDashboard'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection');
        Route::get('/workspaces/selection/dashboard/data', [RoleDashboardController::class, 'selection'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.dashboard.data');
        Route::get('/workspaces/selection/setup', [ProviderWorkspacePageController::class, 'selection'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.setup');
        Route::get('/workspaces/selection/results', [ProviderWorkspacePageController::class, 'selection'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.results');
        Route::get('/workspaces/selection/active', [ProviderWorkspacePageController::class, 'selection'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.active');
        Route::get('/workspaces/selection/unassigned', [ProviderWorkspacePageController::class, 'selection'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.unassigned');
        Route::get('/workspaces/selection/history', [ProviderWorkspacePageController::class, 'selection'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.history');
        Route::get('/workspaces/selection/sessions/create', [ProviderWorkspacePageController::class, 'selectionSessionCreate'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.sessions.create');
        Route::get('/workspaces/selection/sessions/{session}/edit', [ProviderWorkspacePageController::class, 'selectionSessionEdit'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber('session')
            ->name('workspaces.selection.sessions.edit');
        Route::get('/workspaces/selection/sessions/{session}', [ProviderWorkspacePageController::class, 'selectionSessionRecord'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber('session')
            ->name('workspaces.selection.sessions.show');
        Route::get('/workspaces/selection/data', [ProviderController::class, 'selectionOfficerWorkspaceData'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.data');
        Route::get('/workspaces/selection/sessions/data', [SelectionSessionWorkspaceController::class, 'directoryData'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.sessions.data');
        Route::get('/workspaces/selection/sessions/create/data', [SelectionSessionWorkspaceController::class, 'formData'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->name('workspaces.selection.sessions.create.data');
        Route::get('/workspaces/selection/sessions/{session}/edit/data', [SelectionSessionWorkspaceController::class, 'formData'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber('session')
            ->name('workspaces.selection.sessions.edit.data');
        Route::get('/workspaces/decisions', [ProviderWorkspacePageController::class, 'decisionDashboard'])
            ->middleware(['permission:record_final_decisions', 'provider.approved'])
            ->name('workspaces.decisions');
        Route::get('/workspaces/decisions/dashboard/data', [RoleDashboardController::class, 'decisions'])
            ->middleware(['permission:record_final_decisions', 'provider.approved'])
            ->name('workspaces.decisions.dashboard.data');
        Route::get('/workspaces/decisions/pending', [ProviderWorkspacePageController::class, 'decisions'])
            ->middleware(['permission:record_final_decisions', 'provider.approved'])
            ->name('workspaces.decisions.pending');
        Route::get('/workspaces/decisions/waitlist', [ProviderWorkspacePageController::class, 'decisions'])
            ->middleware(['permission:record_final_decisions', 'provider.approved'])
            ->name('workspaces.decisions.waitlist');
        Route::get('/workspaces/decisions/recorded', [ProviderWorkspacePageController::class, 'decisions'])
            ->middleware(['permission:record_final_decisions', 'provider.approved'])
            ->name('workspaces.decisions.recorded');
        Route::get('/workspaces/decisions/data', [ProviderController::class, 'decisionOfficerWorkspaceData'])
            ->middleware(['permission:record_final_decisions', 'provider.approved'])
            ->name('workspaces.decisions.data');
        Route::get('/workspaces/recipients', [ProviderWorkspacePageController::class, 'recipientDashboard'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients');
        Route::get('/workspaces/recipients/dashboard/data', [RoleDashboardController::class, 'recipients'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients.dashboard.data');
        Route::get('/workspaces/recipients/agreements', [ProviderWorkspacePageController::class, 'recipients'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients.agreements');
        Route::get('/workspaces/recipients/active', [ProviderWorkspacePageController::class, 'recipients'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients.active');
        Route::get('/workspaces/recipients/declined', [ProviderWorkspacePageController::class, 'recipients'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients.declined');
        Route::get('/workspaces/recipients/closed', [ProviderWorkspacePageController::class, 'recipients'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients.closed');
        Route::get('/workspaces/recipients/data', [ProviderController::class, 'recipientOfficerWorkspaceData'])
            ->middleware(['permission:manage_recipients', 'provider.approved'])
            ->name('workspaces.recipients.data');
        Route::get('/workspaces/monitoring', [ProviderWorkspacePageController::class, 'monitoringDashboard'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring');
        Route::get('/workspaces/monitoring/dashboard/data', [RoleDashboardController::class, 'monitoring'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring.dashboard.data');
        Route::get('/workspaces/monitoring/review', [ProviderWorkspacePageController::class, 'monitoring'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring.review');
        Route::get('/workspaces/monitoring/follow-ups', [ProviderWorkspacePageController::class, 'monitoring'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring.followups');
        Route::get('/workspaces/monitoring/awaiting', [ProviderWorkspacePageController::class, 'monitoring'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring.awaiting');
        Route::get('/workspaces/monitoring/history', [ProviderWorkspacePageController::class, 'monitoring'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring.history');
        Route::get('/workspaces/monitoring/data', [ProviderController::class, 'monitoringOfficerWorkspaceData'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->name('workspaces.monitoring.data');
        Route::get('/workspaces/monitoring/check-ins/{cycle}', [ProviderRecipientPageController::class, 'monitoringCheckInRecord'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->whereNumber('cycle')
            ->name('workspaces.monitoring.check-ins.show');
        Route::get('/workspaces/monitoring/check-ins/{cycle}/data', [ProviderController::class, 'recipientMonitoringCycleRecord'])
            ->middleware(['permission:manage_monitoring', 'provider.approved'])
            ->whereNumber('cycle')
            ->name('workspaces.monitoring.check-ins.data');
        Route::get('/workspaces/releases', [ProviderWorkspacePageController::class, 'releaseDashboard'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases');
        Route::get('/workspaces/releases/dashboard/data', [RoleDashboardController::class, 'releases'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.dashboard.data');
        Route::get('/workspaces/releases/issues', [ProviderWorkspacePageController::class, 'releases'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.issues');
        Route::get('/workspaces/releases/record', [ProviderWorkspacePageController::class, 'releases'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.record');
        Route::get('/workspaces/releases/upcoming', [ProviderWorkspacePageController::class, 'releases'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.upcoming');
        Route::get('/workspaces/releases/history', [ProviderWorkspacePageController::class, 'releases'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.history');
        Route::get('/workspaces/releases/data', [ProviderController::class, 'benefitReleaseOfficerWorkspaceData'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.data');
        Route::get('/workspaces/releases/schedule', [ProviderWorkspacePageController::class, 'releaseSchedule'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.schedule');
        Route::get('/workspaces/releases/schedule/data', [ProviderController::class, 'benefitReleaseScheduleData'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->name('workspaces.releases.schedule.data');
        Route::get('/workspaces/releases/distributions/{release}', [ProviderRecipientPageController::class, 'benefitReleaseRecord'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->whereNumber('release')
            ->name('workspaces.releases.distributions.show');
        Route::get('/workspaces/releases/distributions/{release}/data', [ProviderController::class, 'recipientBenefitReleaseRecord'])
            ->middleware(['permission:manage_benefit_releases', 'provider.approved'])
            ->whereNumber('release')
            ->name('workspaces.releases.distributions.data');
        Route::get('/workspaces/organization-profile', [ProviderWorkspacePageController::class, 'organizationProfileDashboard'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile');
        Route::get('/workspaces/organization-profile/dashboard/data', [RoleDashboardController::class, 'organizationProfile'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile.dashboard.data');
        Route::get('/workspaces/organization-profile/readiness', [ProviderWorkspacePageController::class, 'organizationProfile'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile.readiness');
        Route::get('/workspaces/organization-profile/public', [ProviderWorkspacePageController::class, 'organizationProfilePublic'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile.public');
        Route::get('/workspaces/organization-profile/contact', [ProviderWorkspacePageController::class, 'organizationProfileContact'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile.contact');
        Route::get('/workspaces/organization-profile/verification', [ProviderWorkspacePageController::class, 'organizationProfileVerification'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile.verification');
        Route::get('/workspaces/organization-profile/data', [ProviderController::class, 'organizationProfileManagerWorkspaceData'])
            ->middleware('permission:manage_profile')
            ->name('workspaces.organization-profile.data');
        Route::patch('/workspaces/organization-profile/{section}', [ProviderController::class, 'updateOrganizationProfileSection'])
            ->middleware('permission:manage_profile')
            ->whereIn('section', ['public', 'contact', 'legal'])
            ->name('workspaces.organization-profile.section.update');
        Route::get('/workspaces/team', [ProviderWorkspacePageController::class, 'teamDashboard'])
            ->middleware('permission:manage_team')
            ->name('workspaces.team');
        Route::get('/workspaces/team/dashboard/data', [RoleDashboardController::class, 'team'])
            ->middleware('permission:manage_team')
            ->name('workspaces.team.dashboard.data');
        Route::get('/workspaces/team/setup', [ProviderWorkspacePageController::class, 'team'])
            ->middleware('permission:manage_team')
            ->name('workspaces.team.setup');
        Route::get('/workspaces/team/active', [ProviderWorkspacePageController::class, 'team'])
            ->middleware('permission:manage_team')
            ->name('workspaces.team.active');
        Route::get('/workspaces/team/suspended', [ProviderWorkspacePageController::class, 'team'])
            ->middleware('permission:manage_team')
            ->name('workspaces.team.suspended');
        Route::get('/workspaces/team/data', [ProviderController::class, 'teamAdministratorWorkspaceData'])
            ->middleware('permission:manage_team')
            ->name('workspaces.team.data');
        Route::get('/workspaces/support', [ProviderWorkspacePageController::class, 'supportDashboard'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support');
        Route::get('/workspaces/support/dashboard/data', [RoleDashboardController::class, 'support'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.dashboard.data');
        Route::get('/workspaces/support/needs-response', [SupportReportController::class, 'providerSupportWorkspace'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.needs-response');
        Route::get('/workspaces/support/waiting', [SupportReportController::class, 'providerSupportWorkspace'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.waiting');
        Route::get('/workspaces/support/platform', [SupportReportController::class, 'providerSupportWorkspace'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.platform');
        Route::get('/workspaces/support/resolved', [SupportReportController::class, 'providerSupportWorkspace'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.resolved');
        Route::get('/workspaces/support/report-platform-issue', [SupportReportController::class, 'providerSupportPlatformReport'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.platform-report');
        Route::get('/workspaces/support/report-platform-issue/data', [SupportReportController::class, 'providerSupportPlatformReportData'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.platform-report.data');
        Route::get('/workspaces/support/cases/{report}', [SupportReportController::class, 'providerSupportCase'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->whereNumber('report')
            ->name('workspaces.support.cases.show');
        Route::get('/workspaces/support/cases/{report}/data', [SupportReportController::class, 'providerSupportCaseData'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->whereNumber('report')
            ->name('workspaces.support.cases.data');
        Route::get('/workspaces/support/data', [SupportReportController::class, 'providerSupportWorkspaceData'])
            ->middleware(['permission:manage_reports', 'provider.approved'])
            ->name('workspaces.support.data');
        Route::get('/workspaces/billing', [ProviderWorkspacePageController::class, 'billingDashboard'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing');
        Route::get('/workspaces/billing/dashboard/data', [RoleDashboardController::class, 'billing'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.dashboard.data');
        Route::get('/workspaces/billing/action', [BillingController::class, 'providerBillingStaffWorkspace'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.action');
        Route::get('/workspaces/billing/active', [BillingController::class, 'providerBillingStaffWorkspace'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.active');
        Route::get('/workspaces/billing/waiting', [BillingController::class, 'providerBillingStaffWorkspace'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.waiting');
        Route::get('/workspaces/billing/completed', [BillingController::class, 'providerBillingStaffWorkspace'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.completed');
        Route::get('/workspaces/billing/data', [BillingController::class, 'providerBillingStaffWorkspaceData'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.data');
        Route::get('/workspaces/billing/services', [BillingController::class, 'providerPage'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->name('workspaces.billing.services');
        Route::get('/workspaces/billing/services/{plan}', [BillingController::class, 'providerPage'])
            ->middleware(['permission:manage_billing', 'provider.approved'])
            ->whereIn('plan', array_keys(config('billing.plans', [])))
            ->name('workspaces.billing.services.show');
        Route::get('/dashboard/data', [ProviderController::class, 'dashboardData'])->name('dashboard.data');
        Route::get('/programs', [ProgramDirectoryController::class, 'index'])
            ->defaults('programFilter', 'all')
            ->middleware('permission:manage_programs')
            ->name('programs');
        Route::get('/programs/data', [ProgramDirectoryController::class, 'data'])
            ->defaults('programFilter', 'all')
            ->middleware('permission:manage_programs')
            ->name('programs.data');
        Route::get('/programs/dashboard/data', [ProgramCoordinatorDashboardController::class, 'data'])
            ->middleware('permission:manage_programs')
            ->name('programs.dashboard.data');
        Route::get('/programs/drafts', [ProgramDirectoryController::class, 'index'])
            ->defaults('programFilter', 'drafts')
            ->middleware('permission:manage_programs')
            ->name('programs.drafts');
        Route::get('/programs/review', [ProgramDirectoryController::class, 'index'])
            ->defaults('programFilter', 'review')
            ->middleware('permission:manage_programs')
            ->name('programs.review');
        Route::get('/programs/published', [ProgramDirectoryController::class, 'index'])
            ->defaults('programFilter', 'published')
            ->middleware('permission:manage_programs')
            ->name('programs.published');
        Route::get('/programs/closed', [ProgramDirectoryController::class, 'index'])
            ->defaults('programFilter', 'closed')
            ->middleware('permission:manage_programs')
            ->name('programs.closed');
        Route::redirect('/exams', '/provider/programs')->name('exams');
        Route::get('/programs/create', [ProviderProgramPageController::class, 'programForm'])->middleware('permission:manage_programs')->name('programs.create');
        Route::redirect('/programs/edit', '/provider/programs')->middleware('permission:manage_programs')->name('programs.edit-directory');
        Route::redirect('/programs/manage', '/provider/programs')->middleware('permission:manage_programs,verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases')->name('programs.manage-directory');
        Route::get('/programs/{scholarship}/data', [ProgramRecordController::class, 'data'])->middleware('permission:manage_programs,verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases')->whereNumber('scholarship')->name('programs.show.data');
        Route::get('/programs/{scholarship}', [ProgramRecordController::class, 'index'])->middleware('permission:manage_programs,verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases')->whereNumber('scholarship')->name('programs.show');
        Route::get('/programs/{scholarship}/updates', [ProviderProgramPageController::class, 'programWorkspace'])->middleware('permission:manage_programs,verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases')->whereNumber('scholarship')->name('programs.updates');
        Route::get('/programs/{scholarship}/monitoring', [ProviderRecipientPageController::class, 'redirectLegacyProgramMonitoring'])->defaults('monitoringView', 'summary')->middleware(['permission:manage_monitoring,manage_benefit_releases,manage_recipients', 'provider.approved'])->whereNumber('scholarship')->name('programs.monitoring');
        Route::get('/programs/{scholarship}/monitoring/plan', [ProviderRecipientPageController::class, 'redirectLegacyProgramMonitoring'])->defaults('monitoringView', 'plan')->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('programs.monitoring.plan');
        Route::get('/programs/{scholarship}/monitoring/academic', [ProviderRecipientPageController::class, 'redirectLegacyProgramMonitoring'])->defaults('monitoringView', 'academic')->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('programs.monitoring.academic');
        Route::get('/programs/{scholarship}/monitoring/releases', [ProviderRecipientPageController::class, 'redirectLegacyProgramMonitoring'])->defaults('monitoringView', 'releases')->middleware(['permission:manage_benefit_releases', 'provider.approved'])->whereNumber('scholarship')->name('programs.monitoring.releases');
        Route::get('/programs/{scholarship}/monitoring/outcomes', [ProviderRecipientPageController::class, 'redirectLegacyProgramMonitoring'])->defaults('monitoringView', 'outcomes')->middleware(['permission:manage_recipients,manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('programs.monitoring.outcomes');
        Route::get('/programs/{scholarship}/edit', [ProviderProgramPageController::class, 'programForm'])->middleware('permission:manage_programs')->name('programs.edit');
        Route::get('/programs/{scholarship}/edit/{step}', [ProviderProgramPageController::class, 'programForm'])
            ->middleware('permission:manage_programs')
            ->whereNumber('scholarship')
            ->where('step', 'basics|support|dates-location|eligibility|application|selection|review')
            ->name('programs.edit.step');
        Route::get('/programs/{scholarship}/applications', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions,manage_recipients', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications');
        Route::get('/programs/{scholarship}/applications/review', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:verify_applications', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications.review');
        Route::get('/programs/{scholarship}/applications/activities', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications.activities');
        Route::get('/programs/{scholarship}/applications/results', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications.results');
        Route::get('/programs/{scholarship}/applications/decisions', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:record_final_decisions', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications.decisions');
        Route::get('/programs/{scholarship}/applications/recipients', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:manage_recipients,manage_monitoring,manage_benefit_releases', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications.recipients');
        Route::get('/programs/{scholarship}/applications/waitlist', [ProviderApplicationPageController::class, 'programApplications'])->middleware(['permission:record_final_decisions', 'provider.approved'])->whereNumber('scholarship')->name('programs.applications.waitlist');
        Route::get('/applications', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions,manage_recipients', 'provider.approved'])->name('applications');
        Route::get('/applications/review', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:verify_applications', 'provider.approved'])->name('applications.review');
        Route::get('/applications/activities', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->name('applications.activities');
        Route::get('/applications/results', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->name('applications.results');
        Route::get('/applications/decisions', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:record_final_decisions', 'provider.approved'])->name('applications.decisions');
        Route::get('/applications/recipients', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:manage_recipients,manage_monitoring,manage_benefit_releases', 'provider.approved'])->name('applications.recipients');
        Route::get('/applications/waitlist', [ProviderApplicationPageController::class, 'applications'])->middleware(['permission:record_final_decisions', 'provider.approved'])->name('applications.waitlist-directory');
        Route::get('/monitoring', [ProviderRecipientPageController::class, 'recipientMonitoringDirectory'])->middleware(['permission:manage_monitoring,manage_benefit_releases,manage_recipients', 'provider.approved'])->name('monitoring');
        Route::get('/monitoring/{scholarship}', [ProviderRecipientPageController::class, 'recipientMonitoringWorkspace'])->middleware(['permission:manage_monitoring,manage_benefit_releases,manage_recipients', 'provider.approved'])->whereNumber('scholarship')->name('monitoring.show');
        Route::get('/monitoring/{scholarship}/plan', [ProviderRecipientPageController::class, 'recipientMonitoringPlanWorkspace'])->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('monitoring.plan');
        Route::get('/monitoring/{scholarship}/academic', [ProviderRecipientPageController::class, 'recipientMonitoringWorkspace'])->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('monitoring.academic');
        Route::get('/monitoring/{scholarship}/releases', [ProviderRecipientPageController::class, 'recipientMonitoringWorkspace'])->middleware(['permission:manage_benefit_releases', 'provider.approved'])->whereNumber('scholarship')->name('monitoring.releases');
        Route::get('/monitoring/{scholarship}/outcomes', [ProviderRecipientPageController::class, 'recipientMonitoringWorkspace'])->middleware(['permission:manage_recipients,manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('monitoring.outcomes');
        Route::get('/profile', [ProviderWorkspacePageController::class, 'profile'])->name('profile');
        Route::get('/profile/details', [ProviderWorkspacePageController::class, 'profile'])->name('profile.details');
        Route::get('/profile/verification', [ProviderWorkspacePageController::class, 'profile'])->name('profile.verification');
        Route::get('/profile/representative', [ProviderWorkspacePageController::class, 'profile'])->name('profile.representative');
        Route::get('/team', [ProviderController::class, 'team'])->middleware('permission:manage_team')->name('team');
        Route::get('/team/data', [ProviderController::class, 'teamData'])->middleware('permission:manage_team')->name('team.data');
        Route::get('/team/accounts/create', [ProviderController::class, 'teamAccountForm'])->middleware('permission:manage_team')->name('team.accounts.create');
        Route::get('/team/accounts/{account}/edit', [ProviderController::class, 'teamAccountForm'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.edit');
        Route::get('/team/accounts/{account}', [ProviderController::class, 'showTeamAccount'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.show');
        Route::post('/team/accounts', [ProviderController::class, 'storeTeamAccount'])->middleware('permission:manage_team')->name('team.accounts.store');
        Route::patch('/team/accounts/{account}', [ProviderController::class, 'updateTeamAccount'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.update');
        Route::patch('/team/accounts/{account}/status', [ProviderController::class, 'updateTeamAccountStatus'])->middleware('permission:manage_team')->whereNumber('account')->name('team.accounts.status');
        Route::get('/billing', [BillingController::class, 'providerPage'])->middleware(['permission:manage_billing', 'provider.approved'])->name('billing');
        Route::get('/billing/requests', [BillingController::class, 'providerPage'])->middleware(['permission:manage_billing', 'provider.approved'])->name('billing.requests');
        Route::get('/billing/data', [BillingController::class, 'providerData'])->middleware(['permission:manage_billing', 'provider.approved'])->name('billing.data');
        Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware(['permission:manage_billing', 'provider.approved', 'throttle:5,1'])->name('billing.checkout');
        Route::post('/billing/sync', [BillingController::class, 'syncCheckout'])->middleware(['permission:manage_billing', 'provider.approved', 'throttle:10,1'])->name('billing.sync');
        Route::get('/billing/{purchase}', [BillingController::class, 'providerWorkspacePage'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace');
        Route::get('/billing/{purchase}/meeting', [BillingController::class, 'providerWorkspacePage'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace.meeting');
        Route::get('/billing/{purchase}/files', [BillingController::class, 'providerWorkspacePage'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace.files');
        Route::get('/billing/{purchase}/updates', [BillingController::class, 'providerWorkspacePage'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace.updates');
        Route::get('/billing/{purchase}/data', [BillingController::class, 'providerWorkspaceData'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.workspace.data');
        Route::patch('/billing/{purchase}/request', [BillingController::class, 'updateProviderRequest'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.request.update');
        Route::post('/billing/{purchase}/meeting', [BillingController::class, 'requestProviderMeeting'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.meeting.request');
        Route::post('/billing/{purchase}/updates', [BillingController::class, 'storeProviderUpdate'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.updates.store');
        Route::post('/billing/{purchase}/files', [BillingController::class, 'uploadProviderFile'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.files.store');
        Route::post('/billing/{purchase}/confirm', [BillingController::class, 'confirmProviderCompletion'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.confirm');
        Route::post('/billing/{purchase}/reopen', [BillingController::class, 'reopenProviderService'])->middleware(['permission:manage_billing', 'provider.approved'])->whereNumber('purchase')->name('billing.reopen');
        Route::get('/reports', [SupportReportController::class, 'providerPage'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports');
        Route::get('/reports/data', [SupportReportController::class, 'providerData'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports.data');
        Route::post('/reports', [SupportReportController::class, 'storeProvider'])->middleware(['permission:manage_reports', 'provider.approved', 'throttle:6,1'])->name('reports.store');
        Route::get('/reports/{report}/attachment', [SupportReportController::class, 'viewAttachment'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports.attachment');
        Route::get('/reports/{report}/applicant-photo', [SupportReportController::class, 'viewApplicantPhoto'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports.applicant-photo');
        Route::patch('/reports/{report}/status', [SupportReportController::class, 'updateStatus'])->middleware(['permission:manage_reports', 'provider.approved'])->name('reports.status');
        Route::redirect('/insights', '/provider/applications?filter=needs_review')->name('insights.redirect');
        Route::redirect('/review', '/provider/applications?filter=needs_review')
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('review');
        Route::get('/profile/data', [ProviderController::class, 'profileData'])->name('profile.data');
        Route::patch('/profile', [ProviderController::class, 'updateProfile'])->name('profile.update');
        Route::post('/profile/logo', [ProviderController::class, 'uploadProviderLogo'])->middleware(['permission:manage_profile', 'throttle:10,1'])->name('profile.logo.store');
        Route::post('/verification-documents', [ProviderController::class, 'uploadVerificationDocument'])->middleware(['permission:manage_profile', 'throttle:10,1'])->name('verification-documents.store');
        Route::get('/verification-documents/{document}/view', [ProviderController::class, 'viewVerificationDocument'])->middleware('permission:manage_profile')->name('verification-documents.view');
        Route::get('/verification-documents/{document}/download', [ProviderController::class, 'downloadVerificationDocument'])->middleware('permission:manage_profile')->name('verification-documents.download');
        Route::delete('/verification-documents/{document}', [ProviderController::class, 'deleteVerificationDocument'])->middleware('permission:manage_profile')->name('verification-documents.destroy');
        Route::get('/insights/data', [ProviderController::class, 'insightsData'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions', 'provider.approved'])->name('insights.data');
        Route::get('/applications/data', [ProviderController::class, 'applicationsData'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions,manage_recipients', 'provider.approved'])->name('applications.data');
        Route::get('/applications/{application}', [ProviderApplicationPageController::class, 'applicationDetail'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions,manage_recipients', 'provider.approved'])->whereNumber('application')->name('applications.show');
        Route::get('/applications/{application}/data', [ProviderController::class, 'applicationDetailData'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions,manage_recipients', 'provider.approved'])->whereNumber('application')->name('applications.show.data');
        Route::patch('/scholarships/{scholarship}/applications/bulk-advance', [ProviderController::class, 'bulkAdvanceApplications'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions', 'provider.approved', 'throttle:10,1'])->whereNumber('scholarship')->name('applications.bulk-advance');
        Route::patch('/applications/{application}/reviewer', [ProviderController::class, 'assignApplicationReviewer'])->middleware(['permission:verify_applications', 'provider.approved'])->whereNumber('application')->name('applications.reviewer');
        Route::patch('/applications/{application}/review-claim', [ProviderController::class, 'claimApplicationReview'])->middleware(['permission:verify_applications', 'provider.approved', 'throttle:20,1'])->whereNumber('application')->name('applications.review-claim');
        Route::get('/applications/{application}/profile-proofs/{document}/view', [ProviderController::class, 'viewApplicantProfileProof'])
            ->whereNumber('application')
            ->whereNumber('document')
            ->middleware(['permission:verify_applications,record_final_decisions', 'provider.approved'])
            ->name('applications.profile-proofs.view');
        Route::get('/applications/{application}/profile-photo', [ProviderController::class, 'viewApplicantProfilePhoto'])
            ->whereNumber('application')
            ->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases', 'provider.approved'])
            ->name('applications.profile-photo.view');
        Route::patch('/applications/{application}/profile-photo-review', [ProviderController::class, 'reviewApplicantProfilePhoto'])
            ->whereNumber('application')
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('applications.profile-photo-review');
        Route::patch('/applications/{application}/profile-verification', [ProviderController::class, 'verifyApplicantProfile'])
            ->whereNumber('application')
            ->middleware(['permission:verify_applications', 'provider.approved'])
            ->name('applications.profile-verification');
        Route::post('/applications/{application}/schedules', [ProviderController::class, 'upsertApplicationSchedule'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->name('applications.schedules.upsert');
        Route::patch('/applications/{application}/schedules/{schedule}', [ProviderController::class, 'updateApplicationScheduleTracking'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->name('applications.schedules.tracking');
        Route::patch('/applications/{application}/decision', [ProviderController::class, 'decideApplication'])->middleware(['permission:verify_applications,manage_selection_activities', 'provider.approved'])->name('applications.decision');
        Route::patch('/applications/{application}/stages/{stage}/result', [ProviderController::class, 'recordApplicationStageResult'])->middleware(['permission:verify_applications,manage_selection_activities', 'provider.approved'])->name('applications.stages.result');
        Route::get('/applications/{application}/stage-readiness', [ApplicationStageOperationController::class, 'applicationReadiness'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber('application')
            ->name('applications.stage-readiness');
        Route::get('/scholarships/{scholarship}/stage-sessions', [ApplicationStageOperationController::class, 'index'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber('scholarship')
            ->name('stage-sessions.index');
        Route::post('/scholarships/{scholarship}/stage-sessions', [ApplicationStageOperationController::class, 'store'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:30,1'])
            ->whereNumber('scholarship')
            ->name('stage-sessions.store');
        Route::get('/scholarships/{scholarship}/stage-sessions/{session}', [ApplicationStageOperationController::class, 'show'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.show');
        Route::get('/scholarships/{scholarship}/stage-sessions/{session}/candidates', [ApplicationStageOperationController::class, 'candidates'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.candidates');
        Route::patch('/scholarships/{scholarship}/stage-sessions/{session}', [ApplicationStageOperationController::class, 'update'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:30,1'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.update');
        Route::post('/scholarships/{scholarship}/stage-sessions/{session}/publish', [ApplicationStageOperationController::class, 'publish'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:20,1'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.publish');
        Route::post('/scholarships/{scholarship}/stage-sessions/{session}/assignments', [ApplicationStageOperationController::class, 'assign'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:30,1'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.assignments.store');
        Route::patch('/scholarships/{scholarship}/stage-sessions/{session}/assignments/{assignment}/reschedule', [ApplicationStageOperationController::class, 'reschedule'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:30,1'])
            ->whereNumber(['scholarship', 'session', 'assignment'])
            ->name('stage-sessions.assignments.reschedule');
        Route::patch('/scholarships/{scholarship}/stage-sessions/{session}/assignments/{assignment}/attendance', [ApplicationStageOperationController::class, 'attendance'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:60,1'])
            ->whereNumber(['scholarship', 'session', 'assignment'])
            ->name('stage-sessions.assignments.attendance');
        Route::post('/scholarships/{scholarship}/stage-sessions/{session}/evidence', [ApplicationStageOperationController::class, 'storeSessionEvidence'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:20,1'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.evidence.store');
        Route::post('/scholarships/{scholarship}/stage-sessions/{session}/assignments/{assignment}/evidence', [ApplicationStageOperationController::class, 'storeAssignmentEvidence'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:20,1'])
            ->whereNumber(['scholarship', 'session', 'assignment'])
            ->name('stage-sessions.assignments.evidence.store');
        Route::post('/scholarships/{scholarship}/stage-sessions/{session}/complete', [ApplicationStageOperationController::class, 'complete'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:20,1'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.complete');
        Route::post('/scholarships/{scholarship}/stage-sessions/{session}/cancel', [ApplicationStageOperationController::class, 'cancel'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved', 'throttle:20,1'])
            ->whereNumber(['scholarship', 'session'])
            ->name('stage-sessions.cancel');
        Route::get('/stage-evidence/{evidence}/view', [ApplicationStageOperationController::class, 'viewEvidence'])
            ->middleware(['permission:manage_selection_activities', 'provider.approved'])
            ->whereNumber('evidence')
            ->name('stage-evidence.view');
        Route::patch('/applications/{application}/final-outcome', [ProviderController::class, 'recordApplicationFinalOutcome'])->middleware(['permission:record_final_decisions', 'provider.approved'])->name('applications.final-outcome');
        Route::patch('/applications/{application}/decision/reopen', [ProviderController::class, 'reopenApplicationDecision'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions', 'provider.approved', 'throttle:10,1'])->whereNumber('application')->name('applications.decision.reopen');
        Route::patch('/applications/{application}/correction', [ProviderController::class, 'handleApplicationCorrection'])->middleware(['permission:verify_applications', 'provider.approved'])->name('applications.correction');
        Route::patch('/applications/{application}/waitlist', [ProviderController::class, 'handleApplicationWaitlist'])->middleware(['permission:record_final_decisions', 'provider.approved'])->name('applications.waitlist');
        Route::patch('/applications/{application}/status', [ProviderController::class, 'updateApplicationStatus'])->middleware(['permission:record_final_decisions', 'provider.approved'])->name('applications.status');
        Route::patch('/documents/{document}/status', [ProviderController::class, 'updateDocumentStatus'])->middleware(['permission:verify_applications', 'provider.approved'])->name('documents.status');
        Route::get('/export/applications', [ProviderController::class, 'exportApplications'])->middleware(['permission:verify_applications,manage_selection_activities,record_final_decisions', 'provider.approved'])->name('export.applications');
        Route::get('/scholarships/{scholarship}/monitoring/export', [ProviderController::class, 'exportRecipientMonitoring'])->middleware(['permission:manage_monitoring,manage_benefit_releases', 'provider.approved'])->whereNumber('scholarship')->name('scholarships.monitoring.export');
        Route::get('/scholarships', [ProviderController::class, 'scholarships'])->middleware('permission:manage_programs,verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases')->name('scholarships');
        Route::post('/scholarships', [ProviderController::class, 'storeScholarship'])->middleware('permission:manage_programs')->name('scholarships.store');
        Route::post('/scholarships/{scholarship}/events', [ProviderController::class, 'upsertScholarshipEvent'])->middleware(['permission:manage_programs', 'provider.approved'])->name('scholarships.events.upsert');
        Route::post('/scholarships/{scholarship}/announcements', [ProviderController::class, 'storeScholarshipAnnouncement'])->middleware(['permission:manage_programs', 'provider.approved'])->name('scholarships.announcements.store');
        Route::get('/scholarships/{scholarship}/monitoring-cycles', [ProviderController::class, 'recipientMonitoringData'])->middleware(['permission:manage_monitoring,manage_benefit_releases,manage_recipients', 'provider.approved'])->whereNumber('scholarship')->name('scholarships.monitoring-cycles.index');
        Route::get('/scholarships/{scholarship}/monitoring-plan', [ProviderController::class, 'recipientMonitoringPlanData'])->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('scholarship')->name('scholarships.monitoring-plan.show');
        Route::put('/scholarships/{scholarship}/monitoring-plan', [ProviderController::class, 'upsertRecipientMonitoringPlan'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('scholarship')->name('scholarships.monitoring-plan.update');
        Route::post('/scholarships/{scholarship}/monitoring-cycles', [ProviderController::class, 'storeRecipientMonitoringCycle'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:10,1'])->whereNumber('scholarship')->name('scholarships.monitoring-cycles.store');
        Route::post('/scholarships/{scholarship}/monitoring-check-ins', [ProviderController::class, 'storeRecipientMonitoringCheckIn'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:10,1'])->whereNumber('scholarship')->name('scholarships.monitoring-check-ins.store');
        Route::post('/monitoring-requirements/{requirement}/applications/{application}/record', [ProviderController::class, 'recordRecipientMonitoringRequirement'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber(['requirement', 'application'])->name('monitoring-requirements.record');
        Route::patch('/monitoring-adjustment-requests/{adjustment}/decision', [ProviderController::class, 'decideRecipientMonitoringAdjustmentRequest'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('adjustment')->name('monitoring-adjustment-requests.decision');
        Route::get('/monitoring-adjustment-requests/{adjustment}/attachment', [ProviderController::class, 'viewRecipientMonitoringAdjustmentAttachment'])->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('adjustment')->name('monitoring-adjustment-requests.attachment');
        Route::post('/monitoring-requirements/{requirement}/applications/{application}/interventions', [ProviderController::class, 'storeRecipientMonitoringIntervention'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber(['requirement', 'application'])->name('monitoring-interventions.store');
        Route::patch('/monitoring-interventions/{intervention}/complete', [ProviderController::class, 'completeRecipientMonitoringIntervention'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('intervention')->name('monitoring-interventions.complete');
        Route::patch('/monitoring-cases/{case}/close', [ProviderController::class, 'closeRecipientMonitoringCase'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('case')->name('monitoring-cases.close');
        Route::patch('/monitoring-cases/{case}/waive', [ProviderController::class, 'waiveRecipientMonitoringCase'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('case')->name('monitoring-cases.waive');
        Route::patch('/monitoring-cases/{case}/reopen', [ProviderController::class, 'reopenRecipientMonitoringCase'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('case')->name('monitoring-cases.reopen');
        Route::patch('/monitoring-submissions/{submission}/review', [ProviderController::class, 'reviewRecipientMonitoringSubmission'])->middleware(['permission:manage_monitoring', 'provider.approved', 'throttle:20,1'])->whereNumber('submission')->name('monitoring-submissions.review');
        Route::get('/monitoring-submissions/{submission}/view', [ProviderController::class, 'viewRecipientMonitoringSubmission'])->middleware(['permission:manage_monitoring', 'provider.approved'])->whereNumber('submission')->name('monitoring-submissions.view');
        Route::post('/scholarships/{scholarship}/benefit-releases', [ProviderController::class, 'storeRecipientBenefitRelease'])->middleware(['permission:manage_benefit_releases', 'provider.approved', 'throttle:10,1'])->whereNumber('scholarship')->name('scholarships.benefit-releases.store');
        Route::post('/benefit-release-records/{record}/result', [ProviderController::class, 'recordRecipientBenefitRelease'])->middleware(['permission:manage_benefit_releases', 'provider.approved', 'throttle:20,1'])->whereNumber('record')->name('benefit-release-records.result');
        Route::get('/benefit-release-records/{record}/receipt', [ProviderController::class, 'viewRecipientBenefitReleaseReceipt'])->middleware(['permission:manage_benefit_releases', 'provider.approved'])->whereNumber('record')->name('benefit-release-records.receipt');
        Route::post('/benefit-receipt-responses/{response}/resolve', [ProviderController::class, 'resolveRecipientBenefitReceiptResponse'])->middleware(['permission:manage_benefit_releases', 'provider.approved', 'throttle:20,1'])->whereNumber('response')->name('benefit-receipt-responses.resolve');
        Route::get('/benefit-receipt-responses/{response}/files/{kind}', [ProviderController::class, 'viewRecipientBenefitReceiptResponseFile'])->middleware(['permission:manage_benefit_releases', 'provider.approved'])->whereNumber('response')->whereIn('kind', ['evidence', 'resolution-proof'])->name('benefit-receipt-responses.file');
        Route::get('/applications/{application}/recipient-record', [ProviderController::class, 'recipientSupportRecord'])->middleware(['permission:manage_recipients,manage_monitoring,manage_benefit_releases', 'provider.approved'])->whereNumber('application')->name('applications.recipient-record');
        Route::post('/applications/{application}/support-decision', [ProviderController::class, 'recordRecipientSupportDecision'])->middleware(['permission:manage_recipients', 'provider.approved', 'throttle:20,1'])->whereNumber('application')->name('applications.support-decision');
        Route::post('/support-decisions/{decision}/resolve', [ProviderController::class, 'resolveRecipientSupportDecisionResponse'])->middleware(['permission:manage_recipients', 'provider.approved', 'throttle:20,1'])->whereNumber('decision')->name('support-decisions.resolve');
        Route::get('/support-decisions/{decision}/files/{kind}', [ProviderController::class, 'viewRecipientSupportDecisionFile'])->middleware(['permission:manage_recipients', 'provider.approved'])->whereNumber('decision')->whereIn('kind', ['decision-document', 'applicant-response', 'resolution-proof'])->name('support-decisions.file');
        Route::patch('/scholarships/{scholarship}/events/{event}/complete', [ProviderController::class, 'completeScholarshipEvent'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->name('scholarships.events.complete');
        Route::patch('/scholarships/{scholarship}/events/{event}/attendance', [ProviderController::class, 'bulkUpdateScholarshipEventAttendance'])->middleware(['permission:manage_selection_activities', 'provider.approved'])->name('scholarships.events.attendance');
        Route::get('/scholarships/{scholarship}', [ProviderController::class, 'showScholarship'])->middleware('permission:manage_programs,verify_applications,manage_selection_activities,record_final_decisions,manage_recipients,manage_monitoring,manage_benefit_releases')->name('scholarships.show');
        Route::put('/scholarships/{scholarship}', [ProviderController::class, 'updateScholarship'])->middleware('permission:manage_programs')->name('scholarships.update');
        Route::delete('/scholarships/{scholarship}', [ProviderController::class, 'destroyScholarship'])->middleware('permission:manage_programs')->name('scholarships.destroy');
        Route::post('/scholarships/{scholarship}/duplicate', [ProviderController::class, 'duplicateScholarship'])->middleware('permission:manage_programs')->name('scholarships.duplicate');
    });

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1')->name('password.email');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
Route::post('/register/verify', [AuthController::class, 'verifyRegistrationCode'])->middleware('throttle:10,1')->name('register.verify');
Route::post('/register/resend-code', [AuthController::class, 'resendRegistrationCode'])->middleware('throttle:3,10')->name('register.resend-code');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
