# OwnPay Stress & Load Testing Guide (k6)

This directory contains the load testing configuration for **OwnPay**. The stress test script simulates real-world payment flows—including health checks, API payment initiation, and loading white-labeled checkout screens—under customizable concurrency targets.

---

## 1. Prerequisites & Installation

To run these tests, you must install **k6** on your local machine or testing server.

### macOS (Homebrew)
```bash
brew install k6
```

### Windows (Chocolatey or winget)
```powershell
# Using Chocolatey
choco install k6

# Or using winget
winget install k6
```

### Linux (Debian/Ubuntu)
```bash
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD19442217C065
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6
```

For other platforms, consult the official [k6 installation guide](https://grafana.com/docs/k6/latest/get-started/installation/).

---

## 2. Configuration & Credentials

The stress test script uses environment variables to authenticate with your OwnPay instance.

1. **Obtain an API Key**:
   - Log in to your OwnPay Admin Panel (`https://ownpay.test/login`).
   - Navigate to **Developers** $\rightarrow$ **API Keys**.
   - Create a new API Key with `write` and `read` scopes for your testing brand/merchant.
   - Copy the generated API token (e.g. `op_sec_...`).

2. **Environment Variables**:
   * `API_URL`: The domain of your target OwnPay server. Defaults to `http://ownpay.test`.
   * `API_KEY`: The active API Bearer token for authentication.
   * `TEST_TYPE`: The load profile to execute: `smoke` (default), `load`, `stress`, or `spike`.

---

## 3. Running the Tests

Execute tests from the root of the project using the `k6 run` command.

### A. Smoke Test (Verifying connection & config)
Run a quick, 1-user execution to make sure the script runs smoothly and endpoint validation passes:
```bash
k6 run --env API_URL="http://ownpay.test" --env API_KEY="your_api_key_here" --env TEST_TYPE="smoke" tests/load/stress_test.js
```

### B. Standard Load Test
Simulate normal peak load (up to 20 virtual users ramping up and staying active for 3 minutes):
```bash
k6 run --env API_URL="http://ownpay.test" --env API_KEY="your_api_key_here" --env TEST_TYPE="load" tests/load/stress_test.js
```

### C. System Stress Test
Gradually ramp up users to 100 to check where the server begins to degrade or hits database connection pool bottlenecks:
```bash
k6 run --env API_URL="http://ownpay.test" --env API_KEY="your_api_key_here" --env TEST_TYPE="stress" tests/load/stress_test.js
```

### D. Spike Test
Instantly push traffic to 150 virtual users to see if the web server recovers gracefully or triggers rate-limiting HTTP 429 exceptions:
```bash
k6 run --env API_URL="http://ownpay.test" --env API_KEY="your_api_key_here" --env TEST_TYPE="spike" tests/load/stress_test.js
```

---

## 4. Key Metrics & SLA Thresholds

The script defines performance thresholds to verify the system health. The test run is considered a **failure** if:
* **`http_req_duration`**: More than 5% of requests take longer than 800ms (`p(95)<800`).
* **`http_req_failed`**: More than 2% of overall HTTP requests fail (`rate<0.02`).

During execution, look at these key output metrics:
* `http_req_duration`: Round-trip time (RTT) for requests. Look at the `p(90)` and `p(95)` values.
* `checks`: Percentage of assertions (like `health status is 200` or `payment created successfully`) that succeeded.
* `http_reqs`: Number of requests processed per second (throughput).

---

## 5. Important Testing Best Practices

1. **Avoid SMTP Flooding**: Ensure your test merchant doesn't trigger email notifications on checkout initiation, or set your mailer configuration (`config/services.php` or `.env`) to a mock driver or a service like Mailhog to avoid flooding email servers during the load test.
2. **Clean up DB Records**: Since creating payments writes records to `op_transactions` and `op_customers`, it is recommended to run stress testing on a **Staging/Staging-like environment** rather than your live Production instance, or clear transaction logs subsequently.
3. **Configure Rate Limits**: If your staging environment has strict rate-limiting middlewares enabled (e.g. `RateLimiterMiddleware`), you may need to disable or increase rate-limit thresholds for the testing API credentials to prevent early `429 Too Many Requests` failures.
