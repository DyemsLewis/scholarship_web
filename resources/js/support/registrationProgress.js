const prefix = 'findscholarship:registration:';
const ttl = 10 * 60 * 1000;

export function registrationStorage() {
    try { return window.sessionStorage; } catch { return null; }
}

export function clearRegistrationProgress(storage, role) {
    try { storage?.removeItem(prefix + role); } catch { /* Registration still works without browser storage. */ }
}

export function readRegistrationProgress(storage, role, now = Date.now()) {
    try {
        const saved = JSON.parse(storage?.getItem(prefix + role) || 'null');
        if (!saved || !/^[A-Za-z0-9]{64}$/.test(saved.token) || typeof saved.email !== 'string'
            || !Number.isFinite(saved.expiresAt) || saved.expiresAt <= now || saved.expiresAt > now + ttl
            || !Number.isFinite(saved.resendAt)) {
            clearRegistrationProgress(storage, role);
            return null;
        }
        return saved;
    } catch { clearRegistrationProgress(storage, role); return null; }
}

export function saveRegistrationProgress(storage, role, data, now = Date.now()) {
    const saved = {
        token: data.token,
        email: data.email,
        resumingAccount: Boolean(data.resumingAccount),
        expiresAt: now + Math.min(ttl, Math.max(0, Number(data.expiresIn) || 600) * 1000),
        resendAt: now + Math.max(0, Number(data.resendAfter) || 0) * 1000,
    };
    try { storage?.setItem(prefix + role, JSON.stringify(saved)); } catch { /* Do not block verification when storage is unavailable. */ }
    return saved;
}
