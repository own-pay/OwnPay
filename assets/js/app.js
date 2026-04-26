/**
 * OwnPay — Unified Admin JS Module
 * Replaces: custom-toast.js, inline JS from index.php
 * Framework: Flowbite (Tailwind CSS)
 * ============================================================
 */

/* ============================================================
   APToast — Toast Notifications (replaces custom-toast.js)
   ============================================================ */
const APToast = (() => {
    function getContainer() {
        let container = document.getElementById('op-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'op-toast-container';
            container.className = 'fixed top-4 right-4 z-[9999] flex flex-col gap-3';
            document.body.appendChild(container);
        }
        return container;
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    return {
        show({ title = 'Notification', description = '', type = 'info', timeout = 5000 } = {}) {
            const container = getContainer();

            const colors = {
                success: { bg: 'text-green-500 bg-green-100 dark:bg-green-800 dark:text-green-200', icon: '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" />' },
                error: { bg: 'text-red-500 bg-red-100 dark:bg-red-800 dark:text-red-200', icon: '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9v4" /><path d="M12 16v.01" />' },
                warning: { bg: 'text-yellow-500 bg-yellow-100 dark:bg-yellow-800 dark:text-yellow-200', icon: '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 16v.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" />' },
                info: { bg: 'text-primary-500 bg-primary-100 dark:bg-primary-800 dark:text-primary-200', icon: '<path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9v4" /><path d="M12 16v.01" />' }
            };

            const c = colors[type] || colors.info;

            const toast = document.createElement('div');
            toast.className = 'flex items-center w-full max-w-sm p-4 text-gray-500 bg-white rounded-xl shadow-lg dark:text-gray-400 dark:bg-gray-800 border border-gray-100 dark:border-gray-700';
            toast.style.animation = 'slideIn 0.3s ease-out';
            toast.setAttribute('role', 'alert');

            toast.innerHTML = `
                <div class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 ${c.bg} rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${c.icon}</svg>
                </div>
                <div class="ms-3 text-sm">
                    <div class="font-semibold text-gray-900 dark:text-white">${escapeHtml(title)}</div>
                    ${description ? `<div class="mt-0.5 text-gray-500 dark:text-gray-400">${escapeHtml(description)}</div>` : ''}
                </div>
                <button type="button" class="ms-auto -mx-1.5 -my-1.5 bg-white text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex items-center justify-center h-8 w-8 dark:text-gray-500 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700" aria-label="Close">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
                </button>
            `;

            toast.querySelector('button').addEventListener('click', () => toast.remove());
            container.prepend(toast);
            setTimeout(() => toast.remove(), timeout);
        }
    };
})();

// Backward-compatible global createToast (used by all existing views)
window.createToast = function ({ title = 'Notification', description = '', svg = '', timeout = 5000, top = 10 } = {}) {
    // Map old SVG stroke color to new type
    let type = 'info';
    if (svg.includes('#d63939') || svg.includes('red')) type = 'error';
    else if (svg.includes('#5f38f9') || svg.includes('check')) type = 'success';
    else if (svg.includes('warning') || svg.includes('yellow')) type = 'warning';

    APToast.show({ title, description, type, timeout });
};

/* ============================================================
   APTheme — Dark/Light Mode Toggle (replaces toggleTheme)
   ============================================================ */
const APTheme = (() => {
    const STORAGE_KEY = 'apTheme';

    function apply(theme) {
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        localStorage.setItem(STORAGE_KEY, theme);
        document.cookie = STORAGE_KEY + '=' + theme + ';path=/;max-age=31536000;SameSite=Lax';
    }

    function toggle() {
        const current = localStorage.getItem(STORAGE_KEY) || 'light';
        apply(current === 'dark' ? 'light' : 'dark');
        updateIcons();
    }

    function updateIcons() {
        const isDark = document.documentElement.classList.contains('dark');
        document.querySelectorAll('[data-theme-toggle-dark]').forEach(el => el.classList.toggle('hidden', isDark));
        document.querySelectorAll('[data-theme-toggle-light]').forEach(el => el.classList.toggle('hidden', !isDark));
    }

    function init() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            apply(saved);
        } else {
            // Default to dark to match server default
            apply('dark');
        }
        updateIcons();
    }

    return { toggle, apply, init };
})();

// Initialize theme on script load (before DOMContentLoaded for flash prevention)
APTheme.init();

// Global backward-compatible
window.toggleTheme = function (theme) { APTheme.apply(theme); APTheme.init(); };

/* ============================================================
   APClipboard — Copy to Clipboard (replaces copyContent)
   ============================================================ */
window.copyContent = function (content, title, description) {
    if (!content) {
        APToast.show({ title: 'Error!', description: 'No content provided to copy.', type: 'error' });
        return;
    }
    navigator.clipboard.writeText(content).then(() => {
        APToast.show({ title: title || 'Copied!', description: description || 'Content copied to clipboard.', type: 'success', timeout: 4000 });
    }).catch(() => {
        APToast.show({ title: 'Failed!', description: 'Unable to copy the content.', type: 'error' });
    });
};

/* ============================================================
   APModal — Flowbite Modal Helpers (replaces Bootstrap modals)
   ============================================================ */
const APModal = (() => {
    function closeAll() {
        document.querySelectorAll('[data-op-modal].op-modal-open').forEach(el => {
            el.classList.add('hidden');
            el.classList.remove('op-modal-open');
        });
        document.body.classList.remove('overflow-hidden');
    }

    function show(modalId) {
        closeAll();
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('op-modal-open');
            document.body.classList.add('overflow-hidden');
        }
    }

    function hide(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('op-modal-open');
            document.body.classList.remove('overflow-hidden');
        }
    }

    return { show, hide, closeAll };
})();

window.closeAllBootstrapModals = APModal.closeAll;
window.closeAllModals = APModal.closeAll;

/* ============================================================
   2FA Verify Modal (replaces show_two_step_verify_tab)
   ============================================================ */
window.show_two_step_verify_tab = function (btnClass) {
    const modal = document.getElementById('model-my-two-step-verify');
    if (!modal) return;

    if (modal.classList.contains('op-modal-open')) {
        const code = document.getElementById('my-two-step-verify-code');
        if (code && code.value === '') {
            code.reportValidity();
        }
    } else {
        APModal.closeAll();
        document.getElementById('my-two-step-verify-btn').value = '.' + btnClass;
        document.getElementById('my-two-step-verify-code').value = '';
        APModal.show('model-my-two-step-verify');
    }
};

window.two_step_verify_tab_btn = function () {
    const btnClass = document.getElementById('my-two-step-verify-btn').value;
    if (btnClass) {
        const el = document.querySelector(btnClass);
        if (el) el.click();
    }
};

/* ============================================================
   Action Confirmation Modal (replaces show_action_confirmation_tab)
   ============================================================ */
window.show_action_confirmation_tab = function (btnClass, title, btnTitle, btnColor) {
    APModal.closeAll();

    const titleEl = document.querySelector('.model-my-action-confirmation-btn-title');
    const confirmBtn = document.getElementById('model-my-action-confirmation-btn');
    const hiddenInput = document.getElementById('my-action-confirmation-btn');

    if (titleEl) titleEl.textContent = title;
    if (confirmBtn) {
        confirmBtn.textContent = btnTitle;
        // Map old Bootstrap colors to Tailwind
        const colorMap = {
            'btn-danger': 'op-btn-danger',
            'btn-primary': 'op-btn-primary',
            'btn-warning': 'text-white bg-yellow-600 hover:bg-yellow-700',
            'btn-success': 'text-white bg-green-600 hover:bg-green-700'
        };
        // Remove old button color classes
        confirmBtn.className = 'op-btn op-btn-sm ' + (colorMap[btnColor] || 'op-btn-primary');
    }
    if (hiddenInput) hiddenInput.value = '.' + btnClass;

    APModal.show('model-my-action-confirmation');
};

window.my_action_confirmation_btn = function () {
    const btnClass = document.getElementById('my-action-confirmation-btn').value;
    if (btnClass) {
        const el = document.querySelector(btnClass);
        if (el) el.click();
    }
    document.getElementById('my-action-confirmation-btn').value = '';
};

/* ============================================================
   APFilter — Filter Dropdown Toggle (replaces toggleFilter)
   ============================================================ */
window.toggleFilter = function (id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.toggle('hidden');
    }
};

window.filter_hide_show = function (tab) {
    const element = document.querySelector('.' + tab);
    if (element) {
        element.classList.toggle('hidden');
    }
};

window.updateFilterIndicator = function(filterClass) {
    var panel = document.querySelector('.' + filterClass);
    if (!panel) return;
    var icon = document.querySelector('[onclick*="filter_hide_show(\'' + filterClass + '\')"]');
    if (!icon) return;
    var parent = icon.parentElement;

    var hasFilter = false;
    panel.querySelectorAll('input, select').forEach(function(el) {
        if (el.tagName === 'SELECT') {
            if (el.selectedIndex > 0) hasFilter = true;
        } else if (el.value && el.value.trim() !== '') {
            hasFilter = true;
        }
    });

    var dot = parent.querySelector('.filter-dot');
    if (hasFilter && !dot) {
        parent.style.position = 'relative';
        dot = document.createElement('span');
        dot.className = 'filter-dot';
        parent.appendChild(dot);
    } else if (!hasFilter && dot) {
        dot.remove();
    }
};

// Auto-update filter indicator when any filter input changes
document.addEventListener('change', function(e) {
    var filterPanel = e.target.closest('.filter-tab-data');
    if (filterPanel) {
        updateFilterIndicator('filter-tab-data');
    }
});

/* ============================================================
   APSelect — Select Dropdown (replaces Choices.js init)
   ============================================================ */
const APSelect = (() => {
    const instances = new Map();

    function init(selector = '.js-select') {
        // Only init if Choices.js is available
        if (typeof Choices === 'undefined') return;

        document.querySelectorAll(selector).forEach(select => {
            if (instances.has(select)) return;

            const isMultiple = select.hasAttribute('multiple');
            const instance = new Choices(select, {
                removeItemButton: select.dataset.remove === 'true' && isMultiple,
                searchEnabled: select.dataset.search !== 'false',
                shouldSort: false,
                placeholder: true,
                placeholderValue: select.dataset.placeholder || 'Select option',
                searchPlaceholderValue: 'Search...',
                allowHTML: false,
            });
            instances.set(select, instance);
        });
    }

    return { init };
})();

window.initChoices = APSelect.init;

/* ============================================================
   Invoice Customer Init (replaces initInvoiceCustomer)
   ============================================================ */
window.initInvoiceCustomer = function () {
    if (typeof Choices === 'undefined') return;
    const el = document.querySelector('.customersList');
    if (!el || el.dataset.choicesInitialized === '1') return;

    window.InvoiceCustomerChoices = new Choices(el, {
        removeItemButton: true,
        searchEnabled: true,
        shouldSort: false,
    });
    el.dataset.choicesInitialized = '1';
};

/* ============================================================
   APTags — Tag Input (replaces initTags)
   ============================================================ */
window.initTags = function () {
    document.querySelectorAll('.js-tags').forEach(input => {
        if (input.dataset.tagsInitialized === '1') return;
        input.dataset.tagsInitialized = '1';

        let tags = [];
        if (input.value.trim() !== '') {
            tags = input.value.split(',').map(t => t.trim()).filter(Boolean);
        }

        const container = document.createElement('div');
        container.className = 'flex flex-wrap gap-2 items-center';
        input.parentNode.insertBefore(container, input);
        container.appendChild(input);

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = input.id;
        container.appendChild(hidden);

        input.removeAttribute('name');
        input.value = '';

        renderTags();

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const value = input.value.trim();
                if (!value || tags.includes(value)) return;
                tags.push(value);
                input.value = '';
                renderTags();
            }
        });

        function renderTags() {
            container.querySelectorAll('.op-tag-item').forEach(tag => tag.remove());
            tags.forEach((tag, index) => {
                const tagEl = document.createElement('span');
                tagEl.className = 'op-tag-item op-badge-primary flex items-center gap-1 cursor-default';
                tagEl.innerHTML = `${tag} <span class="cursor-pointer hover:text-white/70" data-index="${index}">×</span>`;
                container.insertBefore(tagEl, input);
            });
            hidden.value = tags.join(',');
        }

        container.addEventListener('click', function (e) {
            if (e.target.dataset.index !== undefined) {
                tags.splice(parseInt(e.target.dataset.index), 1);
                renderTags();
            }
        });
    });
};

/* ============================================================
   APCSRF — CSRF Token Management
   ============================================================ */
window.apGetCsrf = function () {
    const el = document.querySelector('input[name="csrf_token_default"]');
    return el ? el.value : '';
};

window.apSetCsrf = function (token) {
    document.querySelectorAll('input[name="csrf_token"]').forEach(input => { input.value = token; });
    document.querySelectorAll('input[name="csrf_token_default"]').forEach(input => { input.value = token; });
};

/* ============================================================
   Utility Helpers
   ============================================================ */
window.apEscapeHtml = function(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
};

window.showProgress = function () {
    const root = document.querySelector('.root-print');
    if (root) {
        root.innerHTML = '<div class="flex justify-center items-center py-32"><div class="op-spinner"></div></div>';
    }
};

window.hideProgress = function () {
    // Handled automatically when .root-print gets new HTML content.
};

window.isMobileDevice = function () {
    return window.innerWidth <= 768;
};

/* ============================================================
   Sidebar — Mobile Drawer Toggle
   ============================================================ */
const APSidebar = (() => {
    function toggle() {
        const sidebar = document.getElementById('op-sidebar');
        const backdrop = document.getElementById('op-sidebar-backdrop');
        if (sidebar) {
            sidebar.classList.toggle('-translate-x-full');
            if (backdrop) backdrop.classList.toggle('hidden');
        }
    }

    function closeMobile() {
        if (!isMobileDevice()) return;
        const sidebar = document.getElementById('op-sidebar');
        const backdrop = document.getElementById('op-sidebar-backdrop');
        if (sidebar && !sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.add('-translate-x-full');
            if (backdrop) backdrop.classList.add('hidden');
        }
    }

    return { toggle, closeMobile };
})();

window.APSidebar = APSidebar;

/* ============================================================
   HugeRTE Init (replaces initHugeRTE)
   ============================================================ */
window.initHugeRTE = function (selector = '.hugerte-textArea') {
    if (typeof hugeRTE === 'undefined') return;

    document.querySelectorAll(selector).forEach(el => {
        if (el.dataset.hugerteInitialized) return;

        let options = {
            target: el,
            height: 250,
            menubar: false,
            statusbar: false,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor',
                'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount'
            ],
            toolbar: 'undo redo | formatselect | bold italic backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat',
            content_style: 'body { font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif; font-size: 14px; -webkit-font-smoothing: antialiased; }'
        };

        if (document.documentElement.classList.contains('dark')) {
            options.skin = 'oxide-dark';
            options.content_css = 'dark';
        }

        hugeRTE.init(options);
        el.dataset.hugerteInitialized = 'true';
    });
};

/* ============================================================
   Tooltip Init (Flowbite-native, replaces initToolTips)
   ============================================================ */
window.initToolTips = function () {
    // Flowbite handles tooltips via data-tooltip-target automatically
    // Re-init Flowbite components after AJAX load
    if (typeof initFlowbite === 'function') {
        initFlowbite();
    }
};

/* ============================================================
   DOMContentLoaded — Auto-init
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    APTheme.init();
    APSelect.init();
    if (typeof initTags === 'function') initTags();
    if (typeof initHugeRTE === 'function') initHugeRTE();

    // Modal close listeners
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-op-modal-close]');
        if (btn) {
            const modalId = btn.closest('[data-op-modal]')?.id;
            if (modalId) APModal.hide(modalId);
        }
    });

    // Sidebar mobile backdrop click
    const backdrop = document.getElementById('op-sidebar-backdrop');
    if (backdrop) {
        backdrop.addEventListener('click', APSidebar.toggle);
    }

    // Escape key closes modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            APModal.closeAll();
            // Also close Flowbite modals
            document.querySelectorAll('[tabindex="-1"]:not(.hidden)').forEach(modal => {
                if (modal.id && modal.classList.contains('fixed')) {
                    modal.classList.add('hidden');
                }
            });
        }
    });
});
