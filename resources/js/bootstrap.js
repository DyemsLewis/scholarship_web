import axios from 'axios';
import { installAxiosToastInterceptors } from './support/portalToast';

window.axios = axios;

function resolveAppBaseUrl() {
    const buildScript = document.querySelector('script[src*="/build/assets/"]');

    if (buildScript?.src) {
        const url = new URL(buildScript.src);
        const buildIndex = url.pathname.indexOf('/build/');
        const basePath = buildIndex >= 0 ? url.pathname.slice(0, buildIndex) : '';

        return `${url.origin}${basePath}`.replace(/\/$/, '');
    }

    return window.location.origin;
}

window.appBaseUrl = resolveAppBaseUrl();
window.appAssetUrl = (path) => {
    const cleanPath = String(path ?? '').replace(/^\/+/, '');

    return `${window.appBaseUrl}/${cleanPath}`;
};

window.axios.defaults.baseURL = window.appBaseUrl;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.head.querySelector('meta[name="csrf-token"]');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

installAxiosToastInterceptors(window.axios);
