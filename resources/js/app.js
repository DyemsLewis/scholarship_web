import './bootstrap';
import { createApp } from 'vue';
import AdminPage from './pages/AdminPage.vue';
import AdminLogsPage from './pages/AdminLogsPage.vue';
import AdminReviewsPage from './pages/AdminReviewsPage.vue';
import AdminUsersPage from './pages/AdminUsersPage.vue';
import AccountSetupPage from './pages/AccountSetupPage.vue';
import LandingPage from './pages/LandingPage.vue';
import LoginPage from './pages/LoginPage.vue';
import ProviderPage from './pages/ProviderPage.vue';
import RegisterPage from './pages/RegisterPage.vue';

const appElement = document.getElementById('app');
const pages = {
    accountSetup: AccountSetupPage,
    admin: AdminPage,
    adminLogs: AdminLogsPage,
    adminReviews: AdminReviewsPage,
    adminUsers: AdminUsersPage,
    landing: LandingPage,
    login: LoginPage,
    provider: ProviderPage,
    register: RegisterPage,
};

if (appElement) {
    const page = appElement.dataset.page ?? 'landing';
    const component = pages[page] ?? LandingPage;

    createApp(component).mount(appElement);
}
