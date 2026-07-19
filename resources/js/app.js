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
    adminAccountForm: () => import('./pages/AdminAccountFormPage.vue'),
    adminLogs: () => import('./pages/AdminLogsPage.vue'),
    adminProfile: () => import('./pages/AdminProfilePage.vue'),
    adminProgramReview: () => import('./pages/AdminProgramReviewPage.vue'),
    adminReviews: () => import('./pages/AdminReviewsPage.vue'),
    adminUsers: () => import('./pages/AdminUsersPage.vue'),
    dashboard: () => import('./pages/UserDashboardPage.vue'),
    dashboardApplicationDetail: () => import('./pages/UserApplicationDetailPage.vue'),
    dashboardApplications: () => import('./pages/UserApplicationsPage.vue'),
    dashboardDocuments: () => import('./pages/UserDocumentsPage.vue'),
    dashboardProfile: () => import('./pages/UserProfilePage.vue'),
    dashboardScholarshipDetail: () => import('./pages/UserScholarshipDetailPage.vue'),
    dashboardScholarships: () => import('./pages/UserScholarshipsPage.vue'),
    forgotPassword: () => import('./pages/ForgotPasswordPage.vue'),
    landing: () => import('./pages/LandingPage.vue'),
    login: () => import('./pages/LoginPage.vue'),
    provider: () => import('./pages/ProviderPage.vue'),
    providerApplicationDetail: () => import('./pages/ProviderApplicationDetailPage.vue'),
    providerApplications: () => import('./pages/ProviderApplicationsPage.vue'),
    providerExams: () => import('./pages/ProviderExamsPage.vue'),
    providerInsights: () => import('./pages/ProviderInsightsPage.vue'),
    providerProgramForm: () => import('./pages/ProviderProgramFormPage.vue'),
    providerProfile: () => import('./pages/ProviderProfilePage.vue'),
    providerPrograms: () => import('./pages/ProviderProgramsPage.vue'),
    register: () => import('./pages/RegisterPage.vue'),
    resetPassword: () => import('./pages/ResetPasswordPage.vue'),
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
