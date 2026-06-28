import './bootstrap';
import { createApp } from 'vue';
import AdminAccountFormPage from './pages/AdminAccountFormPage.vue';
import AdminPage from './pages/AdminPage.vue';
import AdminLogsPage from './pages/AdminLogsPage.vue';
import AdminAnalyticsPage from './pages/AdminAnalyticsPage.vue';
import AdminProfilePage from './pages/AdminProfilePage.vue';
import AdminReviewsPage from './pages/AdminReviewsPage.vue';
import AdminUsersPage from './pages/AdminUsersPage.vue';
import AccountSetupPage from './pages/AccountSetupPage.vue';
import ForgotPasswordPage from './pages/ForgotPasswordPage.vue';
import LandingPage from './pages/LandingPage.vue';
import LoginPage from './pages/LoginPage.vue';
import ProviderApplicationsPage from './pages/ProviderApplicationsPage.vue';
import ProviderInsightsPage from './pages/ProviderInsightsPage.vue';
import ProviderPage from './pages/ProviderPage.vue';
import ProviderProgramFormPage from './pages/ProviderProgramFormPage.vue';
import ProviderProfilePage from './pages/ProviderProfilePage.vue';
import ProviderProgramsPage from './pages/ProviderProgramsPage.vue';
import RegisterPage from './pages/RegisterPage.vue';
import ResetPasswordPage from './pages/ResetPasswordPage.vue';
import UserApplicationsPage from './pages/UserApplicationsPage.vue';
import UserDashboardPage from './pages/UserDashboardPage.vue';
import UserDocumentsPage from './pages/UserDocumentsPage.vue';
import UserProfilePage from './pages/UserProfilePage.vue';
import UserScholarshipDetailPage from './pages/UserScholarshipDetailPage.vue';
import UserScholarshipsPage from './pages/UserScholarshipsPage.vue';

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
    accountSetup: AccountSetupPage,
    admin: AdminPage,
    adminAccountForm: AdminAccountFormPage,
    adminAnalytics: AdminAnalyticsPage,
    adminLogs: AdminLogsPage,
    adminProfile: AdminProfilePage,
    adminReviews: AdminReviewsPage,
    adminUsers: AdminUsersPage,
    dashboard: UserDashboardPage,
    dashboardApplications: UserApplicationsPage,
    dashboardDocuments: UserDocumentsPage,
    dashboardProfile: UserProfilePage,
    dashboardScholarshipDetail: UserScholarshipDetailPage,
    dashboardScholarships: UserScholarshipsPage,
    forgotPassword: ForgotPasswordPage,
    landing: LandingPage,
    login: LoginPage,
    provider: ProviderPage,
    providerApplications: ProviderApplicationsPage,
    providerInsights: ProviderInsightsPage,
    providerProgramForm: ProviderProgramFormPage,
    providerProfile: ProviderProfilePage,
    providerPrograms: ProviderProgramsPage,
    register: RegisterPage,
    resetPassword: ResetPasswordPage,
};

if (appElement) {
    loadIconCdn();

    const page = appElement.dataset.page ?? 'landing';
    const component = pages[page] ?? LandingPage;

    createApp(component).mount(appElement);
}
