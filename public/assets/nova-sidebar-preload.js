/**
 * Applies the persisted sidebar width before the browser paints the page.
 * nova-ui.js replaces this temporary root class with the regular sidebar
 * state once the DOM is ready.
 */
(() => {
    const STORAGE_PREFIX = 'nova-sidebar-compact:';
    const MODULE_PATHS = [
        'redmine-mantencion',
        'redmine_tic',
        'monitoreo-servidores',
        'administracion',
        'telegram',
        'emach',
    ];

    const script = document.currentScript;
    const explicitKey = String(script?.dataset.novaSidebarKey || '').trim();
    const path = decodeURIComponent(window.location.pathname || '').toLowerCase();
    const moduleKey = explicitKey || MODULE_PATHS.find(module => path.includes(`/${module}`));

    if (!moduleKey) return;

    try {
        if (window.localStorage.getItem(`${STORAGE_PREFIX}${moduleKey}`) === '1') {
            document.documentElement.classList.add('nova-sidebar-precompact');
        }
    } catch (error) {
        // Storage may be disabled. The regular sidebar initialization still works.
    }
})();
