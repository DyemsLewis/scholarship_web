import './bootstrap';
import { createApp } from 'vue';
import GlobalToastHost from './components/GlobalToastHost.vue';

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
    adminAccountManagerWorkspace: () => import('./pages/AdminAccountManagerWorkspacePage.vue'),
    adminAccountForm: () => import('./pages/AdminAccountFormPage.vue'),
    adminApplicantReview: () => import('./pages/AdminApplicantReviewPage.vue'),
    adminBilling: () => import('./pages/AdminBillingPage.vue'),
    adminFinance: () => import('./pages/AdminFinancePage.vue'),
    adminServiceWorkspace: () => import('./pages/AdminServiceWorkspacePage.vue'),
    adminLogs: () => import('./pages/AdminLogsPage.vue'),
    adminMonitoringReview: () => import('./pages/AdminMonitoringReviewPage.vue'),
    adminProfile: () => import('./pages/AdminProfilePage.vue'),
    adminProgramReview: () => import('./pages/AdminProgramReviewPage.vue'),
    adminProviderReview: () => import('./pages/AdminProviderReviewPage.vue'),
    adminReviewOfficerWorkspace: () => import('./pages/AdminReviewOfficerWorkspacePage.vue'),
    adminSupportOfficerWorkspace: () => import('./pages/AdminSupportOfficerWorkspacePage.vue'),
    adminBillingOfficerWorkspace: () => import('./pages/AdminBillingOfficerWorkspacePage.vue'),
    adminFinanceOfficerWorkspace: () => import('./pages/AdminFinanceOfficerWorkspacePage.vue'),
    adminRecordsOfficerWorkspace: () => import('./pages/AdminRecordsOfficerWorkspacePage.vue'),
    adminPortalManagerWorkspace: () => import('./pages/AdminPortalManagerWorkspacePage.vue'),
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
    provider: () => import('./pages/ProviderPage.vue'),
    providerGovernance: () => import('./pages/ProviderGovernancePage.vue'),
    providerProgramCoordinatorWorkspace: () => import('./pages/ProviderProgramCoordinatorWorkspacePage.vue'),
    providerApplicationReviewerWorkspace: () => import('./pages/ProviderApplicationReviewerWorkspacePage.vue'),
    providerSelectionOfficerWorkspace: () => import('./pages/ProviderSelectionOfficerWorkspacePage.vue'),
    providerDecisionOfficerWorkspace: () => import('./pages/ProviderDecisionOfficerWorkspacePage.vue'),
    providerRecipientOfficerWorkspace: () => import('./pages/ProviderRecipientOfficerWorkspacePage.vue'),
    providerMonitoringOfficerWorkspace: () => import('./pages/ProviderMonitoringOfficerWorkspacePage.vue'),
    providerBenefitReleaseOfficerWorkspace: () => import('./pages/ProviderBenefitReleaseOfficerWorkspacePage.vue'),
    providerOrganizationProfileManagerWorkspace: () => import('./pages/ProviderOrganizationProfileManagerWorkspacePage.vue'),
    providerTeamAdministratorWorkspace: () => import('./pages/ProviderTeamAdministratorWorkspacePage.vue'),
    providerSupportStaffWorkspace: () => import('./pages/ProviderSupportStaffWorkspacePage.vue'),
    providerBillingStaffWorkspace: () => import('./pages/ProviderBillingStaffWorkspacePage.vue'),
    providerApplicationDetail: () => import('./pages/ProviderApplicationDetailPage.vue'),
    providerApplications: () => import('./pages/ProviderApplicationsPage.vue'),
    providerBilling: () => import('./pages/ProviderBillingPage.vue'),
    providerServiceWorkspace: () => import('./pages/ProviderServiceWorkspacePage.vue'),
    providerInsights: () => import('./pages/ProviderInsightsPage.vue'),
    providerProgramForm: () => import('./pages/ProviderProgramFormPage.vue'),
    providerProgramWorkspace: () => import('./pages/ProviderProgramWorkspacePage.vue'),
    providerMonitoringPlan: () => import('./pages/ProviderMonitoringPlanPage.vue'),
    providerMonitoringWorkspace: () => import('./pages/ProviderMonitoringWorkspacePage.vue'),
    providerRecipientMonitoringDirectory: () => import('./pages/ProviderRecipientMonitoringDirectoryPage.vue'),
    providerProfile: () => import('./pages/ProviderProfilePage.vue'),
    providerPrograms: () => import('./pages/ProviderProgramsPage.vue'),
    providerTeam: () => import('./pages/ProviderTeamPage.vue'),
    providerTeamAccountForm: () => import('./pages/ProviderTeamAccountFormPage.vue'),
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

if (appElement) {
    loadIconCdn();
    mountGlobalToastHost();

    const page = appElement.dataset.page ?? 'landing';
    const loadPage = pages[page] ?? pages.landing;

    loadPage().then(({ default: component }) => {
        createApp(component).mount(appElement);
    });
}
