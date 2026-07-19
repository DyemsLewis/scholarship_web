export const PORTAL_TOAST_EVENT = 'portal:toast';

const MUTATING_METHODS = new Set(['post', 'put', 'patch', 'delete']);
const SILENT_PATHS = [
    /\/login\/?$/,
    /\/register\/?$/,
    /\/email\/verification-notification\/?$/,
    /\/notifications(?:\/.*)?$/,
];

function requestPath(config) {
    try {
        return new URL(config?.url ?? '', window.axios?.defaults?.baseURL ?? window.location.origin).pathname;
    } catch {
        return String(config?.url ?? '').split('?')[0];
    }
}

function shouldShowRequestToast(config) {
    if (config?.portalToast === false) {
        return false;
    }

    const method = String(config?.method ?? 'get').toLowerCase();

    if (!MUTATING_METHODS.has(method)) {
        return false;
    }

    const path = requestPath(config);

    return !SILENT_PATHS.some((pattern) => pattern.test(path));
}

function firstValidationError(errors) {
    if (!errors || typeof errors !== 'object') {
        return '';
    }

    for (const value of Object.values(errors)) {
        const message = Array.isArray(value) ? value[0] : value;

        if (typeof message === 'string' && message.trim()) {
            return message.trim();
        }
    }

    return '';
}

function responseMessage(payload) {
    if (!payload || typeof payload !== 'object') {
        return '';
    }

    return firstValidationError(payload.errors)
        || (typeof payload.message === 'string' ? payload.message.trim() : '');
}

export function showPortalToast({
    type = 'success',
    title = type === 'error' ? 'Action failed' : 'Action successful',
    message,
    duration = 4000,
} = {}) {
    const cleanMessage = typeof message === 'string' ? message.trim() : '';

    if (!cleanMessage || typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent(PORTAL_TOAST_EVENT, {
        detail: {
            type: type === 'error' ? 'error' : 'success',
            title,
            message: cleanMessage,
            duration,
        },
    }));
}

export function installAxiosToastInterceptors(axiosInstance) {
    if (!axiosInstance || axiosInstance.__portalToastInterceptorsInstalled) {
        return;
    }

    axiosInstance.__portalToastInterceptorsInstalled = true;

    axiosInstance.interceptors.response.use(
        (response) => {
            if (shouldShowRequestToast(response.config)) {
                const message = responseMessage(response.data);

                if (message) {
                    showPortalToast({ message });
                }
            }

            return response;
        },
        (error) => {
            if (shouldShowRequestToast(error.config)) {
                const message = responseMessage(error.response?.data)
                    || (error.response
                        ? 'The request could not be completed. Please try again.'
                        : 'Unable to connect to the server. Check your connection and try again.');

                showPortalToast({
                    type: 'error',
                    message,
                    duration: 5000,
                });
            }

            return Promise.reject(error);
        },
    );
}
