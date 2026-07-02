/**
 * Integration Test Utilities for OwnPay frontend tests.
 * Provides helpers for testing complete user flows.
 */

/**
 * Create a page object for the checkout page
 * @param {Window} window - The window object
 * @param {Document} document - The document object
 * @returns {object} Checkout page object
 */
export function createCheckoutPage(window, document) {
  return {
    /**
     * Fill in payment form
     * @param {object} data - Payment data
     */
    fillPaymentForm(data) {
      const form = document.getElementById('payment-form');
      if (!form) return;
      
      if (data.gateway) {
        const gatewayInput = form.querySelector('input[name="gateway"]');
        if (gatewayInput) gatewayInput.value = data.gateway;
      }
    },

    /**
     * Submit payment form
     */
    submitForm() {
      const form = document.getElementById('payment-form');
      if (form) {
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.click();
      }
    },

    /**
     * Get checkout configuration
     * @returns {object} Checkout config
     */
    getConfig() {
      return window.OP_CHECKOUT_CONFIG || {};
    },

    /**
     * Get manual gateways
     * @returns {object} Manual gateways
     */
    getManualGateways() {
      return window.OP_MANUAL_GATEWAYS || {};
    },

    /**
     * Check if timer is active
     * @returns {boolean} Timer active status
     */
    isTimerActive() {
      const timerEl = document.getElementById('timer');
      return timerEl && timerEl.textContent.length > 0;
    },

    /**
     * Get active gateway tab
     * @returns {string} Active tab name
     */
    getActiveTab() {
      const activeTab = document.querySelector('.ck-tab.on');
      return activeTab ? activeTab.dataset.t : null;
    },

    /**
     * Switch to a gateway tab
     * @param {string} tabName - Tab name to switch to
     */
    switchTab(tabName) {
      const tab = document.querySelector(`.ck-tab[data-t="${tabName}"]`);
      if (tab) tab.click();
    },
  };
}

/**
 * Create a page object for the admin page
 * @param {Window} window - The window object
 * @param {Document} document - The document object
 * @returns {object} Admin page object
 */
export function createAdminPage(window, document) {
  return {
    /**
     * Toggle sidebar
     */
    toggleSidebar() {
      const toggle = document.getElementById('sidebar-toggle');
      if (toggle) toggle.click();
    },

    /**
     * Check if sidebar is collapsed
     * @returns {boolean} Sidebar collapsed status
     */
    isSidebarCollapsed() {
      const sidebar = document.getElementById('sidebar');
      return sidebar ? sidebar.classList.contains('op-sidebar-collapsed') : false;
    },

    /**
     * Expand a navigation group
     * @param {string} groupName - Navigation group name
     */
    expandNavGroup(groupName) {
      const groups = document.querySelectorAll('.op-nav-group');
      for (const group of groups) {
        const link = group.querySelector(':scope > .op-nav-item-link');
        if (link && link.textContent.includes(groupName)) {
          link.click();
          break;
        }
      }
    },

    /**
     * Check if navigation group is expanded
     * @param {string} groupName - Navigation group name
     * @returns {boolean} Expanded status
     */
    isNavGroupExpanded(groupName) {
      const groups = document.querySelectorAll('.op-nav-group');
      for (const group of groups) {
        const link = group.querySelector(':scope > .op-nav-item-link');
        if (link && link.textContent.includes(groupName)) {
          return group.classList.contains('op-nav-expanded');
        }
      }
      return false;
    },

    /**
     * Get CSRF token
     * @returns {string} CSRF token
     */
    getCsrfToken() {
      return window.OP_CSRF || '';
    },

    /**
     * Get success alerts
     * @returns {NodeList} Success alerts
     */
    getSuccessAlerts() {
      return document.querySelectorAll('.op-alert-success');
    },

    /**
     * Get error alerts
     * @returns {NodeList} Error alerts
     */
    getErrorAlerts() {
      return document.querySelectorAll('.op-alert-error');
    },
  };
}

/**
 * Create a page object for the devices page
 * @param {Window} window - The window object
 * @param {Document} document - The document object
 * @returns {object} Devices page object
 */
export function createDevicesPage(window, document) {
  return {
    /**
     * Get device list
     * @returns {NodeList} Device items
     */
    getDeviceList() {
      return document.querySelectorAll('.device-item');
    },

    /**
     * Get device status
     * @param {string} deviceUuid - Device UUID
     * @returns {string} Device status
     */
    getDeviceStatus(deviceUuid) {
      const device = document.querySelector(`.device-item[data-uuid="${deviceUuid}"]`);
      if (!device) return null;
      
      const statusEl = device.querySelector('.device-status');
      return statusEl ? statusEl.textContent : null;
    },

    /**
     * Click generate OTP button
     */
    clickGenerateOtp() {
      const btn = document.getElementById('generate-otp-btn');
      if (btn) btn.click();
    },

    /**
     * Get OTP display text
     * @returns {string} OTP text
     */
    getOtpDisplay() {
      const otpEl = document.getElementById('otp-display');
      return otpEl ? otpEl.textContent : '';
    },

    /**
     * Get pairing status
     * @returns {string} Pairing status
     */
    getPairingStatus() {
      const statusEl = document.getElementById('pairing-status');
      return statusEl ? statusEl.textContent : '';
    },
  };
}

/**
 * Create a page object for the SMS center page
 * @param {Window} window - The window object
 * @param {Document} document - The document object
 * @returns {object} SMS center page object
 */
export function createSmsCenterPage(window, document) {
  return {
    /**
     * Set regex pattern
     * @param {string} pattern - Regex pattern
     */
    setRegexPattern(pattern) {
      const input = document.getElementById('regex-pattern');
      if (input) input.value = pattern;
    },

    /**
     * Set sample text
     * @param {string} text - Sample text
     */
    setSampleText(text) {
      const textarea = document.getElementById('sample-text');
      if (textarea) textarea.value = text;
    },

    /**
     * Click test regex button
     */
    clickTestRegex() {
      const btn = document.getElementById('test-regex-btn');
      if (btn) btn.click();
    },

    /**
     * Get regex result
     * @returns {string} Regex result
     */
    getRegexResult() {
      const resultEl = document.getElementById('regex-result');
      return resultEl ? resultEl.textContent : '';
    },

    /**
     * Set SMS input
     * @param {string} text - SMS text
     */
    setSmsInput(text) {
      const textarea = document.getElementById('sms-input');
      if (textarea) textarea.value = text;
    },

    /**
     * Click analyze button
     */
    clickAnalyze() {
      const btn = document.getElementById('analyze-btn');
      if (btn) btn.click();
    },

    /**
     * Get analysis result
     * @returns {string} Analysis result
     */
    getAnalysisResult() {
      const resultEl = document.getElementById('analysis-result');
      return resultEl ? resultEl.textContent : '';
    },
  };
}

/**
 * Create a page object for the webhook test page
 * @param {Window} window - The window object
 * @param {Document} document - The document object
 * @returns {object} Webhook test page object
 */
export function createWebhookTestPage(window, document) {
  return {
    /**
     * Set webhook URL
     * @param {string} url - Webhook URL
     */
    setWebhookUrl(url) {
      const input = document.getElementById('webhook-url');
      if (input) input.value = url;
    },

    /**
     * Set webhook event
     * @param {string} event - Webhook event
     */
    setWebhookEvent(event) {
      const select = document.getElementById('webhook-event');
      if (select) select.value = event;
    },

    /**
     * Click test webhook button
     */
    clickTestWebhook() {
      const btn = document.getElementById('test-webhook-btn');
      if (btn) btn.click();
    },

    /**
     * Get webhook result
     * @returns {string} Webhook result
     */
    getWebhookResult() {
      const resultEl = document.getElementById('webhook-result');
      return resultEl ? resultEl.textContent : '';
    },

    /**
     * Get webhook history
     * @returns {NodeList} Webhook log items
     */
    getWebhookHistory() {
      return document.querySelectorAll('.webhook-log');
    },

    /**
     * Get webhook log status
     * @param {number} logId - Log ID
     * @returns {string} Log status
     */
    getWebhookLogStatus(logId) {
      const log = document.querySelector(`.webhook-log[data-id="${logId}"]`);
      if (!log) return null;
      
      const statusEl = log.querySelector('.webhook-status');
      return statusEl ? statusEl.textContent : null;
    },
  };
}

/**
 * Create a mock API server for integration tests
 * @returns {object} Mock API server
 */
export function createMockApiServer() {
  const endpoints = new Map();
  
  return {
    /**
     * Register a mock endpoint
     * @param {string} method - HTTP method
     * @param {string} url - Endpoint URL
     * @param {Function} handler - Request handler
     */
    register(method, url, handler) {
      const key = `${method.toUpperCase()}:${url}`;
      endpoints.set(key, handler);
    },

    /**
     * Handle a request
     * @param {string} method - HTTP method
     * @param {string} url - Endpoint URL
     * @param {object} body - Request body
     * @returns {Promise<object>} Response
     */
    async handle(method, url, body = null) {
      const key = `${method.toUpperCase()}:${url}`;
      const handler = endpoints.get(key);
      
      if (!handler) {
        return { ok: false, status: 404, data: { error: 'Not found' } };
      }
      
      return handler(body);
    },

    /**
     * Clear all registered endpoints
     */
    clear() {
      endpoints.clear();
    },
  };
}

/**
 * Create flow test helpers
 * @param {Window} window - The window object
 * @param {Document} document - The document object
 * @returns {object} Flow test helpers
 */
export function createFlowHelpers(window, document) {
  return {
    /**
     * Wait for a condition to be true
     * @param {Function} condition - Condition function
     * @param {number} timeout - Timeout in ms
     * @returns {Promise<boolean>} Whether condition was met
     */
    async waitForCondition(condition, timeout = 5000) {
      const startTime = Date.now();
      while (Date.now() - startTime < timeout) {
        if (condition()) return true;
        await new Promise(resolve => setTimeout(resolve, 100));
      }
      return false;
    },

    /**
     * Wait for an element to exist
     * @param {string} selector - CSS selector
     * @param {number} timeout - Timeout in ms
     * @returns {Promise<Element|null>} Element or null
     */
    async waitForElement(selector, timeout = 5000) {
      return this.waitForCondition(() => document.querySelector(selector), timeout)
        .then(() => document.querySelector(selector));
    },

    /**
     * Simulate user input
     * @param {string} selector - CSS selector
     * @param {string} value - Input value
     */
    simulateInput(selector, value) {
      const input = document.querySelector(selector);
      if (input) {
        input.value = value;
        input.dispatchEvent(new window.Event('input', { bubbles: true }));
        input.dispatchEvent(new window.Event('change', { bubbles: true }));
      }
    },

    /**
     * Simulate button click
     * @param {string} selector - CSS selector
     */
    simulateClick(selector) {
      const element = document.querySelector(selector);
      if (element) {
        element.click();
      }
    },

    /**
     * Get form data
     * @param {string} formSelector - Form CSS selector
     * @returns {object} Form data
     */
    getFormData(formSelector) {
      const form = document.querySelector(formSelector);
      if (!form) return {};
      
      const formData = new FormData(form);
      const data = {};
      for (const [key, value] of formData.entries()) {
        data[key] = value;
      }
      return data;
    },
  };
}
