(function () {
    var STORAGE_KEY = 'op-ref-dark-mode';
    var root = document.documentElement;

    function applyMode(mode) {
        if (mode === 'dark') {
            root.setAttribute('data-op-theme', 'dark');
        } else {
            root.removeAttribute('data-op-theme');
        }
    }

    var stored = null;
    try {
        stored = window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
        // localStorage unavailable (private browsing, disabled storage) - fall back to default.
    }

    var defaultMode = root.getAttribute('data-op-theme-default') || 'light';
    var initialMode = stored || defaultMode;
    applyMode(initialMode);

    var toggle = document.getElementById('op-ref-dark-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var current = root.getAttribute('data-op-theme') === 'dark' ? 'dark' : 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            applyMode(next);
            try {
                window.localStorage.setItem(STORAGE_KEY, next);
            } catch (e) {
                // Ignore persistence failure - toggle still works for this page view.
            }
        });
    }
})();
