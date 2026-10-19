import './bootstrap';
import { createApp } from 'vue';
import GlobalToastHost from './components/GlobalToastHost.vue';
import StaffFilePreviewHost from './components/StaffFilePreviewHost.vue';

function loadIconCdn() {
    if (document.querySelector('link[data-icon-cdn="fontawesome"]')) {
        return;
    }

    const iconStylesheet = document.createElement('link');
    iconStylesheet.rel = 'stylesheet';
    iconStylesheet.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css';
    iconStylesheet.crossOrigin = 'anonymous';
    iconStylesheet.referrerPolicy = 'no-referrer';
    iconStylesheet.dataset.iconCdn = 'fontawesome';
    document.head.appendChild(iconStylesheet);
}

const appElement = document.getElementById('app');
const pages = {
    accountSetup: () => import('./pages/AccountSetupPage.vue'),
    admin: () => import('./pages/AdminPage.vue'),
    adminAccountManagerDashboard: () => import('./pages/admin/accounts/AccountManagerDashboardPage.vue'),
    adminAccountManagerWorkspace: () => import('./pages/AdminAccountManagerWorkspacePage.vue'),
    adminAccountDirectory: () => import('./pages/admin/accounts/AccountDirectoryPage.vue'),
    adminAccountAttention: () => import('./pages/admin/accounts/AccountAttentionPage.vue'),
    adminAccountCreate: () => import('./pages/admin/accounts/AccountCreatePage.vue'),
    adminAccountRecord: () => import('./pages/admin/accounts/AccountRecordPage.vue'),
    adminAccountForm: () => import('./pages/AdminAccountFormPage.vue'),
    adminApplicantReview: () => import('./pages/AdminApplicantReviewPage.vue'),
    adminBilling: () => import('./pages/AdminBillingPage.vue'),
    adminFinance: () => import('./pages/AdminFinancePage.vue'),
    adminLogs: () => import('./pages/AdminLogsPage.vue'),
    adminMonitoringReview: () => import('./pages/AdminMonitoringReviewPage.vue'),
    adminProfile: () => import('./pages/AdminProfilePage.vue'),
    adminProgramReview: () => import('./pages/AdminProgramReviewPage.vue'),
    adminProviderReview: () => import('./pages/AdminProviderReviewPage.vue'),
    adminReviewOfficerWorkspace: () => import('./pages/AdminReviewOfficerWorkspacePage.vue'),
    adminReviewOverview: () => import('./pages/admin/reviews/ReviewOverviewPage.vue'),
    adminReviewProviderQueue: () => import('./pages/admin/reviews/ReviewQueuePage.vue'),
    adminReviewProgramQueue: () => import('./pages/admin/reviews/ReviewQueuePage.vue'),
    adminReviewApplicantQueue: () => import('./pages/admin/reviews/ReviewQueuePage.vue'),
    adminReviewSelectionActivityQueue: () => import('./pages/admin/reviews/ReviewQueuePage.vue'),
    adminReviewSelectionActivityRecord: () => import('./pages/admin/reviews/SelectionActivityRecordPage.vue'),
    adminReviewBenefitQueue: () => import('./pages/admin/reviews/ReviewQueuePage.vue'),
    adminReviewMonitoringQueue: () => import('./pages/admin/reviews/ReviewQueuePage.vue'),
    adminReviewProviderRecord: () => import('./pages/admin/reviews/ProviderReviewRecordPage.vue'),
    adminReviewProgramRecord: () => import('./pages/admin/reviews/ProgramReviewRecordPage.vue'),
    adminReviewApplicantRecord: () => import('./pages/admin/reviews/ApplicantReviewRecordPage.vue'),
    adminReviewMonitoringRecord: () => import('./pages/admin/reviews/MonitoringReviewRecordPage.vue'),
    adminSupportOfficerWorkspace: () => import('./pages/AdminSupportOfficerWorkspacePage.vue'),
    adminSupportOverview: () => import('./pages/admin/support/SupportOverviewPage.vue'),
    adminSupportCaseDirectory: () => import('./pages/admin/support/SupportCaseDirectoryPage.vue'),
    adminSupportCaseRecord: () => import('./pages/admin/support/SupportCaseRecordPage.vue'),
    adminBillingOfficerWorkspace: () => import('./pages/AdminBillingOfficerWorkspacePage.vue'),
    adminBillingOverview: () => import('./pages/admin/billing/BillingOverviewPage.vue'),
    adminBillingRequestDirectory: () => import('./pages/admin/billing/BillingRequestDirectoryPage.vue'),
    adminServiceOverview: () => import('./pages/admin/billing/AdminServiceOverviewPage.vue'),
    adminServiceDelivery: () => import('./pages/admin/billing/AdminServiceDeliveryPage.vue'),
    adminServiceFiles: () => import('./pages/admin/billing/AdminServiceFilesPage.vue'),
    adminServiceUpdates: () => import('./pages/admin/billing/AdminServiceUpdatesPage.vue'),
    adminServiceMeeting: () => import('./pages/admin/billing/AdminServiceMeetingPage.vue'),
    adminServiceCloseout: () => import('./pages/admin/billing/AdminServiceCloseoutPage.vue'),
    adminServiceHistory: () => import('./pages/admin/billing/AdminServiceHistoryPage.vue'),
    adminFinanceOfficerWorkspace: () => import('./pages/AdminFinanceOfficerWorkspacePage.vue'),
    adminFinanceOverview: () => import('./pages/admin/finance/FinanceOverviewPage.vue'),
    adminFinancePayments: () => import('./pages/admin/finance/FinancePaymentsPage.vue'),
    adminFinanceExceptions: () => import('./pages/admin/finance/FinanceExceptionsPage.vue'),
    adminFinanceReceipt: () => import('./pages/admin/finance/FinanceReceiptPage.vue'),
    adminRecordsOfficerWorkspace: () => import('./pages/AdminRecordsOfficerWorkspacePage.vue'),
    adminRecordsOfficerDashboard: () => import('./pages/admin/records/RecordsOfficerDashboardPage.vue'),
    adminRecordsActivity: () => import('./pages/admin/records/RecordsActivityPage.vue'),
    adminRecordsActivityRecord: () => import('./pages/admin/records/RecordsActivityRecordPage.vue'),
    adminRecordsExports: () => import('./pages/admin/records/RecordsExportsPage.vue'),
    adminPortalManagerWorkspace: () => import('./pages/AdminPortalManagerWorkspacePage.vue'),
    adminPortalManagerArea: () => import('./pages/admin/portal/PortalManagerAreaPage.vue'),
    adminSuperAdminWorkspace: () => import('./pages/admin/governance/SuperAdminWorkspacePage.vue'),
    adminSuperAdminArea: () => import('./pages/admin/governance/SuperAdminAreaPage.vue'),
    adminReviews: () => import('./pages/AdminReviewsPage.vue'),
    adminUsers: () => import('./pages/AdminUsersPage.vue'),
    dashboard: () => import('./pages/UserDashboardPage.vue'),
    dashboardApplicationDetail: () => import('./pages/UserApplicationDetailPage.vue'),
    dashboardApplications: () => import('./pages/UserApplicationsPage.vue'),
    dashboardDocuments: () => import('./pages/UserDocumentsPage.vue'),
    dashboardMonitoring: () => import('./pages/UserMonitoringPage.vue'),
    dashboardMonitoringDetail: () => import('./pages/UserMonitoringDetailPage.vue'),
    dashboardProfile: () => import('./pages/UserProfilePage.vue'),
    dashboardProviders: () => import('./pages/UserProvidersPage.vue'),
    dashboardScholarshipDetail: () => import('./pages/UserScholarshipDetailPage.vue'),
    dashboardScholarships: () => import('./pages/UserScholarshipsPage.vue'),
    forgotPassword: () => import('./pages/ForgotPasswordPage.vue'),
    landing: () => import('./pages/LandingPage.vue'),
    login: () => import('./pages/LoginPage.vue'),
    provider: () => import('./pages/provider/ProviderManagerDashboardPage.vue'),
    providerApplicationReviewerDashboard: () => import('./pages/provider/reviews/ApplicationReviewerDashboardPage.vue'),
    providerApplicationReviewerWorkspace: () => import('./pages/provider/reviews/ApplicationReviewDirectoryPage.vue'),
    providerApplicationReviewRecord: () => import('./pages/provider/reviews/ApplicationReviewRecordPage.vue'),
    providerSelectionOfficerDashboard: () => import('./pages/provider/selection/SelectionOfficerDashboardPage.vue'),
    providerSelectionOfficerWorkspace: () => import('./pages/provider/selection/SelectionActivityDirectoryPage.vue'),
    providerSelectionSessionForm: () => import('./pages/provider/selection/SelectionSessionFormPage.vue'),
    providerSelectionSessionRecord: () => import('./pages/provider/selection/SelectionSessionRecordPage.vue'),
    providerSelectionActivityRecord: () => import('./pages/provider/selection/SelectionActivityRecordPage.vue'),
    providerDecisionOfficerDashboard: () => import('./pages/provider/decisions/DecisionOfficerDashboardPage.vue'),
    providerDecisionOfficerWorkspace: () => import('./pages/provider/decisions/FinalDecisionDirectoryPage.vue'),
    providerFinalDecisionRecord: () => import('./pages/provider/decisions/FinalDecisionRecordPage.vue'),
    providerRecipientOfficerDashboard: () => import('./pages/provider/recipients/RecipientOfficerDashboardPage.vue'),
    providerRecipientOfficerWorkspace: () => import('./pages/provider/recipients/RecipientDirectoryPage.vue'),
    providerRecipientRecord: () => import('./pages/provider/recipients/RecipientRecordPage.vue'),
    providerMonitoringOfficerDashboard: () => import('./pages/provider/monitoring/MonitoringOfficerDashboardPage.vue'),
    providerMonitoringOfficerWorkspace: () => import('./pages/provider/monitoring/MonitoringDirectoryPage.vue'),
    providerMonitoringCheckInRecord: () => import('./pages/provider/monitoring/MonitoringCheckInRecordPage.vue'),
    providerBenefitReleaseOfficerDashboard: () => import('./pages/provider/releases/BenefitReleaseOfficerDashboardPage.vue'),
    providerBenefitReleaseOfficerWorkspace: () => import('./pages/provider/releases/BenefitReleaseDirectoryPage.vue'),
    providerBenefitReleaseSchedule: () => import('./pages/provider/releases/BenefitReleaseSchedulePage.vue'),
    providerBenefitReleaseRecord: () => import('./pages/provider/releases/BenefitReleaseRecordPage.vue'),
    providerOrganizationProfileManagerDashboard: () => import('./pages/provider/organization/OrganizationProfileDashboardPage.vue'),
    providerOrganizationProfileManagerWorkspace: () => import('./pages/provider/organization/OrganizationProfileReadinessPage.vue'),
    providerOrganizationProfilePublic: () => import('./pages/provider/organization/OrganizationPublicProfilePage.vue'),
    providerOrganizationProfileContact: () => import('./pages/provider/organization/OrganizationContactPage.vue'),
    providerOrganizationProfileVerification: () => import('./pages/provider/organization/OrganizationVerificationPage.vue'),
    providerTeamAdministratorDashboard: () => import('./pages/provider/team/TeamAdministratorDashboardPage.vue'),
    providerTeamAdministratorWorkspace: () => import('./pages/provider/team/TeamDirectoryPage.vue'),
    providerSupportStaffDashboard: () => import('./pages/provider/support/SupportStaffDashboardPage.vue'),
    providerSupportStaffWorkspace: () => import('./pages/provider/support/SupportCaseDirectoryPage.vue'),
    providerSupportCase: () => import('./pages/provider/support/SupportCaseRecordPage.vue'),
    providerSupportPlatformReport: () => import('./pages/provider/support/SupportPlatformReportPage.vue'),
    providerBillingStaffDashboard: () => import('./pages/provider/billing/BillingStaffDashboardPage.vue'),
    providerBillingStaffWorkspace: () => import('./pages/provider/billing/BillingRequestDirectoryPage.vue'),
    providerServiceCatalog: () => import('./pages/provider/billing/BillingServiceCatalogPage.vue'),
    providerServiceDetail: () => import('./pages/provider/billing/BillingServiceDetailPage.vue'),
    providerServiceIntake: () => import('./pages/provider/billing/BillingServiceIntakePage.vue'),
    providerServiceReview: () => import('./pages/provider/billing/BillingServiceReviewPage.vue'),
    providerServiceOverview: () => import('./pages/provider/billing/BillingServiceOverviewPage.vue'),
    providerServiceMeeting: () => import('./pages/provider/billing/BillingServiceMeetingPage.vue'),
    providerServiceFiles: () => import('./pages/provider/billing/BillingServiceFilesPage.vue'),
    providerServiceUpdates: () => import('./pages/provider/billing/BillingServiceUpdatesPage.vue'),
    providerServiceCloseout: () => import('./pages/provider/billing/BillingServiceCloseoutPage.vue'),
    providerServiceHistory: () => import('./pages/provider/billing/BillingServiceHistoryPage.vue'),
    providerProgramForm: () => import('./pages/provider/programs/ProgramFormPage.vue'),
    providerMonitoringPlan: () => import('./pages/provider/monitoring/MonitoringPlanPage.vue'),
    providerProgramCoordinatorDashboard: () => import('./pages/provider/programs/ProgramCoordinatorDashboardPage.vue'),
    providerProgramDirectory: () => import('./pages/provider/programs/ProgramDirectoryPage.vue'),
    providerProgramRecord: () => import('./pages/provider/programs/ProgramRecordPage.vue'),
    providerTeamAccountForm: () => import('./pages/provider/team/TeamAccountFormPage.vue'),
    register: () => import('./pages/RegisterPage.vue'),
    resetPassword: () => import('./pages/ResetPasswordPage.vue'),
    supportReportQueue: () => import('./pages/SupportReportQueuePage.vue'),
};

function mountGlobalToastHost() {
    if (document.getElementById('portal-toast-host')) {
        return;
    }

    const toastHost = document.createElement('div');
    toastHost.id = 'portal-toast-host';
    document.body.appendChild(toastHost);
    createApp(GlobalToastHost).mount(toastHost);
}

function mountStaffFilePreviewHost() {
    if (!/^\/(?:provider|admin)(?:\/|$)/.test(window.location.pathname)) {
        return;
    }

    if (document.getElementById('staff-file-preview-host')) {
        return;
    }

    const previewHost = document.createElement('div');
    previewHost.id = 'staff-file-preview-host';
    document.body.appendChild(previewHost);
    createApp(StaffFilePreviewHost).mount(previewHost);
}

if (appElement) {
    loadIconCdn();
    mountGlobalToastHost();
    mountStaffFilePreviewHost();

    const page = appElement.dataset.page ?? 'landing';
    const loadPage = pages[page] ?? pages.landing;

    loadPage().then(({ default: component }) => {
        createApp(component).mount(appElement);
    });
}
