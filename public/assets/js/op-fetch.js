/**
 * OwnPay Fetch Wrapper - CSRF-protected AJAX.
 * OWASP: Auto-attaches CSRF token, validates response, prevents open redirect.
 */
(function () {
    "use strict";

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) { return meta.getAttribute("content"); }
        const input = document.querySelector('input[name="_csrf_token"]');
        return input ? input.value : "";
    }

    /**
     * @param {string} url
     * @param {object} options
     * @returns {Promise<{ok: boolean, status: number, data: any, error?: string}>}
     */
    window.opFetch = async function (url, options = {}) {
        // OWASP: Prevent open redirect / SSRF via URL validation
        if (url.startsWith("//") || /^https?:\/\//i.test(url)) {
            const allowed = window.location.origin;
            if (!url.startsWith(allowed)) {
                return { ok: false, status: 0, data: null, error: "Cross-origin requests blocked" };
            }
        }

        const defaults = {
            method: "GET",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": getCsrfToken(),
            },
            credentials: "same-origin",
        };

        const merged = Object.assign({}, defaults, options);
        merged.headers = Object.assign({}, defaults.headers, options.headers || {});

        // Auto JSON body
        if (merged.body && typeof merged.body === "object" && !(merged.body instanceof FormData)) {
            merged.headers["Content-Type"] = "application/json";
            merged.body = JSON.stringify(merged.body);
        }

        try {
            const response = await fetch(url, merged);
            let data = null;

            const contentType = response.headers.get("Content-Type") || "";
            if (contentType.includes("application/json")) {
                data = await response.json();
            } else {
                data = await response.text();
            }

            return {
                ok: response.ok,
                status: response.status,
                data: data,
                error: response.ok ? null : (data?.message || data?.error || `HTTP ${response.status}`),
            };
        } catch (err) {
            return {
                ok: false,
                status: 0,
                data: null,
                error: err.message || "Network error",
            };
        }
    };

    /**
     * Shorthand POST
     */
    window.opPost = function (url, body) {
        return window.opFetch(url, { method: "POST", body: body });
    };

    /**
     * Shorthand DELETE
     */
    window.opDelete = function (url) {
        return window.opFetch(url, { method: "DELETE" });
    };

    /**
     * Load HTML fragment into container (SPA-style).
     */
    window.opLoadFragment = async function (url, containerId) {
        const container = document.getElementById(containerId);
        if (!container) { return; }
        container.innerHTML = '<div class="op-loading">Loading...</div>';
        const result = await window.opFetch(url);
        if (result.ok) {
            container.innerHTML = result.data;
        } else {
            container.innerHTML = '<div class="op-alert op-alert-danger">Failed to load: ' + (result.error || "") + "</div>";
        }
    };

})();
