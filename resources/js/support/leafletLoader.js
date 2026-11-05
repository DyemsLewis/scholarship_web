let loading;

export function loadLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (loading) return loading;
    loading = new Promise((resolve, reject) => {
        if (!document.getElementById('leaflet-local-css')) {
            const stylesheet = document.createElement('link');
            stylesheet.id = 'leaflet-local-css';
            stylesheet.rel = 'stylesheet';
            stylesheet.href = '/maps/leaflet/leaflet.css';
            document.head.appendChild(stylesheet);
        }
        const script = document.createElement('script');
        script.src = '/maps/leaflet/leaflet.js';
        script.async = true;
        const timer = setTimeout(() => finish(new Error('Map library timed out.')), 15000);
        function finish(error) {
            clearTimeout(timer);
            script.onload = script.onerror = null;
            if (error || !window.L) {
                script.remove();
                loading = undefined;
                reject(error || new Error('Map library unavailable.'));
            } else resolve(window.L);
        }
        script.onload = () => finish();
        script.onerror = () => finish(new Error('Map library could not load.'));
        document.head.appendChild(script);
    });
    return loading;
}
