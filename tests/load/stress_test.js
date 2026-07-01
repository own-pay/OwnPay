import http from 'k6/http';
import { check, group, sleep } from 'k6';

// Read configuration from environment variables
const BASE_URL = __ENV.API_UR || 'https://support.ownpay.org'; // Base domain under test
const API_KEY = __ENV.API_KY || 'op_c5cc6a76.65e81405d23296da43102404df5d0c9203c5ab0a55261c23'; // Merchant API Bearer key
//const TEST_TYPE = __ENV.TEST_TYPE || 'smoke';
const TEST_TYPE = __ENV.TEST_TYPE || 'load';
//const TEST_TYPE = __ENV.TEST_TYPE || 'stress';
//const TEST_TYPE = __ENV.TEST_TYPE || 'spike'; // Profile name (smoke, load, stress, spike)

// Define different load profiles
const profiles = {
    smoke: {
        vus: 1,
        duration: '10s',
    },
    load: {
        stages: [
            { duration: '1m', target: 50 },  // Ramp-up to 20 virtual users
            { duration: '3m', target: 50 },  // Hold at 20 users
            { duration: '1m', target: 0 },   // Ramp-down to 0
        ],
    },
    stress: {
        stages: [
            { duration: '1m', target: 20 },  // Normal load
            { duration: '2m', target: 20 },
            { duration: '1m', target: 50 },  // Ramping up pressure
            { duration: '3m', target: 50 },
            { duration: '1m', target: 100 }, // Pushing systems limit
            { duration: '3m', target: 100 },
            { duration: '2m', target: 0 },   // Cool down
        ],
    },
    spike: {
        stages: [
            { duration: '10s', target: 10 },
            { duration: '1m', target: 150 }, // Quick spike
            { duration: '2m', target: 150 },
            { duration: '10s', target: 10 },  // Quick drop
        ],
    }
};

const profile = profiles[TEST_TYPE] || profiles.smoke;

export const options = {
    stages: profile.stages || null,
    vus: profile.vus || null,
    duration: profile.duration || null,
    thresholds: {
        // Enforce performance SLAs:
        // 95% of request responses must be faster than 800ms
        http_req_duration: ['p(95)<800'],
        // System-wide failure rate must be under 2%
        http_req_failed: ['rate<0.02'],
    },
};

export default function () {
    // ---------------------------------------------------------
    // Workflow 1: Health Check Endpoint
    // ---------------------------------------------------------
    group('Health Check (DB & Services Ping)', function () {
        const url = `${BASE_URL}/api/v1/health`;
        const params = {
            headers: {
                'Authorization': `Bearer ${API_KEY}`,
                'Accept': 'application/json',
            },
        };
        
        const res = http.get(url, params);
        
        check(res, {
            'health status is 200': (r) => r.status === 200,
            'health check body format is correct': (r) => {
                try {
                    const json = JSON.parse(r.body);
                    return json.success === true && json.data.status === 'healthy';
                } catch (e) {
                    return false;
                }
            },
        });
        sleep(1);
    });

    // ---------------------------------------------------------
    // Workflow 2: Payment Initiation + Customer Checkout Flow
    // ---------------------------------------------------------
    group('Payment Initiation and Checkout', function () {
        if (!API_KEY) {
            // API key is required to initiate payments
            return;
        }

        const url = `${BASE_URL}/api/v1/payments`;
        const payload = JSON.stringify({
            amount: '125.50',
            currency: 'BDT',
            customer_email: `k6-user-${__VU}-${__ITER}@example.com`,
            customer_name: `k6 Load Test VU ${__VU} Iteration ${__ITER}`,
            reference: `k6-ref-${__VU}-${__ITER}-${Date.now()}`,
        });

        const params = {
            headers: {
                'Authorization': `Bearer ${API_KEY}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        };

        const res = http.post(url, payload, params);

        let token = '';
        const paymentSuccess = check(res, {
            'payment created successfully (201)': (r) => r.status === 201,
            'payment response contains checkout token': (r) => {
                try {
                    const json = JSON.parse(r.body);
                    token = json.data.token;
                    return typeof token === 'string' && token.length > 0;
                } catch (e) {
                    return false;
                }
            },
        });

        // If payment intent was created successfully, simulate customer checkout actions
        if (paymentSuccess && token) {
            // Mimic human think time (half a second)
            sleep(0.5);

            // Step 2a: Render the checkout screen (hits Twig engine, routes, database queries)
            const checkoutUrl = `${BASE_URL}/checkout/${token}`;
            const checkoutRes = http.get(checkoutUrl);

            check(checkoutRes, {
                'checkout page rendered (200)': (r) => r.status === 200,
            });

            // Step 2b: Query checkout status (polling or initial state check)
            sleep(1);
            const statusUrl = `${BASE_URL}/checkout/${token}/status`;
            const statusRes = http.get(statusUrl);

            check(statusRes, {
                'checkout status returned (200)': (r) => r.status === 200,
            });
        }

        sleep(1);
    });

    // ---------------------------------------------------------
    // Workflow 3: Customer Management Flow (Create & Get)
    // ---------------------------------------------------------
    group('Customer Management', function () {
        if (!API_KEY) {
            return;
        }

        const email = `k6-cust-${__VU}-${__ITER}-${Date.now()}@example.com`;
        const phone = `88017${Math.floor(10000000 + Math.random() * 90000000)}`;
        const createUrl = `${BASE_URL}/api/v1/customers`;
        const createPayload = JSON.stringify({
            name: `k6 Customer VU ${__VU} Iteration ${__ITER}`,
            email: email,
            phone: phone,
        });

        const params = {
            headers: {
                'Authorization': `Bearer ${API_KEY}`,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        };

        // Step 3a: Create Customer
        const createRes = http.post(createUrl, createPayload, params);

        const createdSuccess = check(createRes, {
            'customer created successfully (201)': (r) => r.status === 201,
            'customer response contains id': (r) => {
                try {
                    const json = JSON.parse(r.body);
                    return json.success === true && (typeof json.data.id === 'number' || typeof json.data.id === 'string');
                } catch (e) {
                    return false;
                }
            },
        });

        if (createdSuccess) {
            // Mimic human think time
            sleep(0.5);

            // Step 3b: Get Customer details using the phone identifier (bypasses WAF blocking '@' in path)
            const getUrl = `${BASE_URL}/api/v1/customers/${encodeURIComponent(phone)}`;
            const getRes = http.get(getUrl, params);

            check(getRes, {
                'customer retrieved successfully (200)': (r) => {
                    if (r.status !== 200) {
                        console.log(`[DEBUG] GET customer failed. URL: ${getUrl} | Status: ${r.status} | Response: ${r.body}`);
                    }
                    return r.status === 200;
                },
                'customer details match': (r) => {
                    try {
                        const json = JSON.parse(r.body);
                        return json.success === true && json.data.phone === phone;
                    } catch (e) {
                        return false;
                    }
                },
            });
        }

        sleep(1);
    });
}

