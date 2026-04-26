/**
 * Own Pay — Shared Fetch Utility
 * Replaces $.ajax() calls with native fetch() API.
 * 
 * Usage:
 *   opFetch('customer-list', { search_input: 'foo', page: 1 })
 *     .then(res => { ... })
 *     .catch(err => { ... });
 */

const OP_SVG_SUCCESS = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5f38f9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-8 h-8"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>`;
const OP_SVG_ERROR = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d63939" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-8 h-8"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9v4" /><path d="M12 16v.01" /></svg>`;

/**
 * Get current CSRF token from the page.
 */
function apGetCsrf() {
    const el = document.querySelector('input[name="csrf_token_default"]') ||
        document.querySelector('input[name="csrf_token"]');
    return el ? el.value : '';
}

/**
 * Rotate CSRF tokens in all matching hidden inputs.
 */
function apRotateCsrf(newToken) {
    if (!newToken) return;
    document.querySelectorAll('input[name="csrf_token"]').forEach(el => el.value = newToken);
    document.querySelectorAll('input[name="csrf_token_default"]').forEach(el => el.value = newToken);
}

/**
 * Core fetch wrapper.
 * @param {string}  action       The server action name (e.g. "customer-list").
 * @param {Object}  data         Key-value pairs to POST.
 * @param {Object}  [opts]       Optional overrides.
 * @param {string}  [opts.url]   Override POST URL (default: current dashboard URL).
 * @param {boolean} [opts.raw]   If true, return the raw Response instead of parsed JSON.
 * @returns {Promise<Object>}    Parsed JSON response with CSRF auto-rotated.
 */
async function opFetch(action, data = {}, opts = {}) {
    const url = opts.url || window.OP_DASHBOARD_URL || '';

    const body = new URLSearchParams();
    body.append('action', action);
    body.append('csrf_token', apGetCsrf());

    for (const [key, value] of Object.entries(data)) {
        body.append(key, value);
    }

    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    });

    if (!response.ok) {
        if (response.status === 401) {
            // Session expired — redirect to login
            APToast.show({ title: 'Session Expired', description: 'Your session has expired. Redirecting to login...', type: 'warning', timeout: 3000 });
            setTimeout(() => { window.location.href = window.location.origin + '/login'; }, 2000);
            throw new Error('Session expired');
        }
        if (response.status === 403) {
            throw new Error('Access denied. You do not have permission to perform this action.');
        }
        if (response.status === 429) {
            throw new Error('Too many requests. Please wait a moment and try again.');
        }
        throw new Error(`Server error (${response.status}). Please try again later.`);
    }

    if (opts.raw) return response;

    let json;
    try {
        const text = await response.text();
        json = text ? JSON.parse(text) : { status: 'false', title: 'Empty Response', message: 'The server returned an empty response.' };
    } catch (e) {
        json = { status: 'false', title: 'Invalid Response', message: 'The server returned an invalid response.' };
    }
    apRotateCsrf(json.csrf_token);
    return json;
}

/**
 * Show a success toast.
 */
function apToastSuccess(title, message) {
    if (typeof createToast === 'function') {
        createToast({ title, description: message, svg: OP_SVG_SUCCESS, timeout: 6000, top: 70 });
    }
}

/**
 * Show an error toast with contextual messages.
 */
function apToastError(titleOrError, message) {
    let title = 'Something Wrong!';
    let desc = 'For further assistance, please contact our support team.';

    if (titleOrError instanceof Error) {
        // Contextual error from opFetch
        if (titleOrError.message === 'Session expired') return; // Already handled
        if (titleOrError.message === 'Failed to fetch' || titleOrError.message === 'NetworkError when attempting to fetch resource.') {
            title = 'Connection Lost';
            desc = 'Check your internet connection and try again.';
        } else if (titleOrError.message.includes('Access denied')) {
            title = 'Access Denied';
            desc = titleOrError.message;
        } else if (titleOrError.message.includes('Too many requests')) {
            title = 'Rate Limited';
            desc = titleOrError.message;
        } else if (titleOrError.message.includes('Server error')) {
            title = 'Server Error';
            desc = titleOrError.message;
        }
    } else if (typeof titleOrError === 'string') {
        title = titleOrError;
        if (message) desc = message;
    }

    if (typeof createToast === 'function') {
        createToast({ title, description: desc, svg: OP_SVG_ERROR, timeout: 6000, top: 70 });
    }
}

/**
 * Shorthand toast — used across views as apToast('success', title, message).
 */
function apToast(type, title, message) {
    if (typeof APToast !== 'undefined') {
        APToast.show({ title, description: message, type, timeout: 6000 });
    } else if (typeof createToast === 'function') {
        const svg = type === 'success' ? OP_SVG_SUCCESS : OP_SVG_ERROR;
        createToast({ title, description: message, svg, timeout: 6000, top: 70 });
    }
}

/**
 * Handle a standard server response (status check + toast).
 * @param {Object}   res           Parsed JSON from server.
 * @param {Function} [onSuccess]   Callback if res.status === 'true'.
 * @param {Function} [onError]     Callback if res.status !== 'true'.
 */
function apHandleResponse(res, onSuccess, onError) {
    if (res.status === 'true') {
        apToastSuccess(res.title, res.message);
        if (onSuccess) onSuccess(res);
    } else {
        apToastError(res.title, res.message);
        if (onError) onError(res);
    }
}

/**
 * Show a spinner inside a button and return a restore function.
 * @param {string|Element} selectorOrEl  CSS selector or DOM element.
 * @param {string} [size='sm']           Spinner size: 'sm' or 'md'.
 * @returns {Function}                   Call the returned function to restore original content.
 */
function apBtnLoading(selectorOrEl, size = 'sm') {
    const el = typeof selectorOrEl === 'string' ? document.querySelector(selectorOrEl) : selectorOrEl;
    if (!el) return () => { };
    const original = el.innerHTML;
    const dim = size === 'sm' ? '16px' : '20px';
    el.innerHTML = `<div class="op-spinner" style="width:${dim};height:${dim};border-width:2px"></div>`;
    return () => { el.innerHTML = original; };
}

/**
 * Show a spinner inside the global loader area.
 * @returns {Function} Call to clear the spinner.
 */
function apGlobalLoading() {
    const el = document.querySelector('.global-loaderSpinner');
    if (!el) return () => { };
    el.innerHTML = '<div class="op-spinner"></div>';
    return () => { el.innerHTML = ''; };
}

/**
 * Generate skeleton table rows for loading state.
 * @param {number} cols     Number of columns.
 * @param {number} [rows=5] Number of skeleton rows.
 * @returns {string}        HTML string.
 */
function apSkeletonRows(cols, rows = 5) {
    let html = '';
    for (let r = 0; r < rows; r++) {
        html += '<tr class="animate-pulse">';
        for (let c = 0; c < cols; c++) {
            html += '<td class="p-4"><div class="h-3.5 bg-gray-200 rounded-md dark:bg-gray-700 w-full my-1"></div></td>';
        }
        html += '</tr>';
    }
    return html;
}

/**
 * Generate an empty-state HTML block for tables.
 * @param {string} title   Heading text.
 * @param {string} message Description text.
 * @returns {string}       HTML string.
 */
function apEmptyState(title, message) {
    var safeTitle = typeof apEscapeHtml === 'function' ? apEscapeHtml(title || 'No Data') : (title || 'No Data');
    var safeMsg = typeof apEscapeHtml === 'function' ? apEscapeHtml(message || '') : (message || '');
    return '<div class="py-16 text-center"><div class="flex flex-col items-center justify-center">' +
        '<div class="w-20 h-20 mb-4 rounded-full bg-gray-50 dark:bg-gray-800 flex items-center justify-center">' +
        '<svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-gray-400 dark:text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 14l2 2l4 -4" /></svg>' +
        '</div>' +
        '<h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">' + safeTitle + '</h3>' +
        '<p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm mx-auto">' + safeMsg + '</p>' +
        '</div></div>';
}
