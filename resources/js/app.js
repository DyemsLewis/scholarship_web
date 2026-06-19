import './bootstrap';
import { createApp } from 'vue';
import AdminPage from './pages/AdminPage.vue';
import AdminLogsPage from './pages/AdminLogsPage.vue';
import AdminReviewsPage from './pages/AdminReviewsPage.vue';
import AdminUsersPage from './pages/AdminUsersPage.vue';
import AccountSetupPage from './pages/AccountSetupPage.vue';
import ForgotPasswordPage from './pages/ForgotPasswordPage.vue';
import LandingPage from './pages/LandingPage.vue';
import LoginPage from './pages/LoginPage.vue';
import ProviderApplicationsPage from './pages/ProviderApplicationsPage.vue';
import ProviderPage from './pages/ProviderPage.vue';
import ProviderProgramsPage from './pages/ProviderProgramsPage.vue';
import RegisterPage from './pages/RegisterPage.vue';
import ResetPasswordPage from './pages/ResetPasswordPage.vue';
import UserApplicationsPage from './pages/UserApplicationsPage.vue';
import UserDashboardPage from './pages/UserDashboardPage.vue';
import UserProfilePage from './pages/UserProfilePage.vue';
import UserScholarshipDetailPage from './pages/UserScholarshipDetailPage.vue';
import UserScholarshipsPage from './pages/UserScholarshipsPage.vue';

const appElement = document.getElementById('app');
const pages = {
    accountSetup: AccountSetupPage,
    admin: AdminPage,
    adminLogs: AdminLogsPage,
    adminReviews: AdminReviewsPage,
    adminUsers: AdminUsersPage,
    dashboard: UserDashboardPage,
    dashboardApplications: UserApplicationsPage,
    dashboardProfile: UserProfilePage,
    dashboardScholarshipDetail: UserScholarshipDetailPage,
    dashboardScholarships: UserScholarshipsPage,
    forgotPassword: ForgotPasswordPage,
    landing: LandingPage,
    login: LoginPage,
    provider: ProviderPage,
    providerApplications: ProviderApplicationsPage,
    providerPrograms: ProviderProgramsPage,
    register: RegisterPage,
    resetPassword: ResetPasswordPage,
};

if (appElement) {
    const page = appElement.dataset.page ?? 'landing';
    const component = pages[page] ?? LandingPage;

    createApp(component).mount(appElement);
}
