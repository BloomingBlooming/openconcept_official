(() => {
    'use strict';

    // Load in <head> before paint. This is a browser preference, not a shared
    // workspace setting; unavailable storage must never prevent sign-in.
    const key = 'openconcept.ui.theme';
    const normalize = value => value === 'dark' ? 'dark' : 'light';
    function apply(value) {
        const mode = normalize(value);
        document.documentElement.dataset.theme = mode;
        const color = document.querySelector('meta[name="theme-color"]');
        if (color) color.content = mode === 'dark' ? '#111513' : '#f6f5f2';
        const select = document.getElementById('uiThemeSelect');
        if (select) select.value = mode;
        window.dispatchEvent(new CustomEvent('openconcept:theme-change', { detail: { mode } }));
        return mode;
    }
    let initial = 'light';
    try { initial = localStorage.getItem(key); } catch (_) { /* Keep the default. */ }
    apply(initial);
    window.OpenConceptTheme = Object.freeze({
        get: () => normalize(document.documentElement.dataset.theme),
        set(value) {
            const mode = apply(value);
            try { localStorage.setItem(key, mode); } catch (_) { /* Still applies to this page. */ }
            return mode;
        },
    });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) {
            try { if (event.storageArea === localStorage) apply(event.newValue); } catch (_) { /* Storage is unavailable. */ }
        }
    });
})();
