/**
 * API Test Utilities for OwnPay frontend tests.
 * Provides mock helpers for testing API endpoint interactions.
 */

/**
 * Create a mock fetch response
 * @param {object} data - Response data
 * @param {number} status - HTTP status code
 * @param {boolean} ok - Whether response is ok
 * @returns {object} Mock fetch response
 */
export function createMockResponse(data, status = 200, ok = true) {
  return {
    ok,
    status,
    headers: {
      get: (name) => {
        if (name === 'Content-Type') return 'application/json';
        return null;
      },
    },
    json: () => Promise.resolve(data),
    text: () => Promise.resolve(JSON.stringify(data)),
  };
}

/**
 * Create a mock error response
 * @param {string} message - Error message
 * @param {number} status - HTTP status code
 * @returns {object} Mock error response
 */
export function createErrorResponse(message, status = 400) {
  return createMockResponse({ error: message }, status, false);
}

/**
 * Create a mock success response
 * @param {object} data - Response data
 * @returns {object} Mock success response
 */
export function createSuccessResponse(data) {
  return createMockResponse({ success: true, ...data }, 200, true);
}

/**
 * Setup fetch mock for testing
 * @returns {object} Object with fetch mock and helper methods
 */
export function setupFetchMock() {
  const fetchMock = vi.fn();
  
  // Default success response
  fetchMock.mockResolvedValue(createSuccessResponse({}));
  
  return {
    fetch: fetchMock,
    
    /**
     * Mock a successful API call
     * @param {object} data - Response data
     */
    mockSuccess(data) {
      fetchMock.mockResolvedValueOnce(createSuccessResponse(data));
    },
    
    /**
     * Mock an API error
     * @param {string} message - Error message
     * @param {number} status - HTTP status code
     */
    mockError(message, status = 400) {
      fetchMock.mockResolvedValueOnce(createErrorResponse(message, status));
    },
    
    /**
     * Mock a network error
     * @param {string} message - Error message
     */
    mockNetworkError(message = 'Network failure') {
      fetchMock.mockRejectedValueOnce(new Error(message));
    },
    
    /**
     * Get the last fetch call arguments
     * @returns {object} Last call arguments
     */
    getLastCall() {
      const calls = fetchMock.mock.calls;
      if (calls.length === 0) return null;
      
      const [url, options] = calls[calls.length - 1];
      return { url, options };
    },
    
    /**
     * Get all fetch calls
     * @returns {Array} All fetch calls
     */
    getAllCalls() {
      return fetchMock.mock.calls.map(([url, options]) => ({ url, options }));
    },
    
    /**
     * Clear all mock calls
     */
    clear() {
      fetchMock.mockClear();
    },
  };
}

/**
 * Create mock API test data for payments
 */
export const mockPaymentData = {
  initiate: {
    amount: '100.00',
    currency: 'USD',
    callback_url: 'https://example.com/callback',
    redirect_url: 'https://example.com/success',
    customer_email: 'test@example.com',
    customer_name: 'Test Customer',
    gateway: 'stripe',
  },
  
  response: {
    success: true,
    payment_id: 'PAY-123',
    checkout_url: 'https://checkout.ownpay.test/pay/PAY-123',
    status: 'pending',
  },
  
  status: {
    payment_id: 'PAY-123',
    status: 'completed',
    amount: '100.00',
    currency: 'USD',
    gateway: 'stripe',
    gateway_trx_id: 'txn_abc123',
  },
};

/**
 * Create mock API test data for devices
 */
export const mockDeviceData = {
  pairing: {
    otp: '123456',
    device_uuid: 'dev-uuid-123',
  },
  
  status: {
    devices: [
      {
        device_uuid: 'dev-uuid-123',
        status: 'connected',
        last_seen: '2026-07-02T10:00:00Z',
      },
    ],
  },
};

/**
 * Create mock API test data for SMS
 */
export const mockSmsData = {
  testRegex: {
    pattern: '/OTP[:\\s]*(\\d{4,6})/i',
    sample_text: 'Your OTP is 123456',
    matches: ['123456'],
  },
  
  analyze: {
    messages: [
      {
        sender: 'BKASH',
        body: 'You have received 500 BDT',
        parsed: {
          amount: 500,
          currency: 'BDT',
          type: 'credit',
        },
      },
    ],
  },
};
