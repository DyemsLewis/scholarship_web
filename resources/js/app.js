import './bootstrap';
import { createApp } from 'vue';
import LandingPage from './pages/LandingPage.vue';
import LoginPage from './pages/LoginPage.vue';
import RegisterPage from './pages/RegisterPage.vue';

const appElement = document.getElementById('app');
const pages = {
    landing: LandingPage,
    login: LoginPage,
    register: RegisterPage,
};

if (appElement) {
    const page = appElement.dataset.page ?? 'landing';
    const component = pages[page] ?? LandingPage;

    createApp(component).mount(appElement);
}
