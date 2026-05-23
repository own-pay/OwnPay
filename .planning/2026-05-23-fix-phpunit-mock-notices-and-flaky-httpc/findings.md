# Technical Findings - PHPUnit Notices & Flaky HttpClient Test

## 1. Flaky HttpClient Test
- **Location**: `tests/Security/SecurityRemediationTest.php` (`testHttpClientPatchMethod` and `testHttpClientStripsSensitiveHeadersOnCrossOriginRedirect`)
- **Root Cause**:
  - `testHttpClientPatchMethod` asserts that the response status returned by `https://httpbin.org/patch` is strictly identical to `200`.
  - Recently, `httpbin.org` has been highly unstable and returned a `502 Bad Gateway` error.
  - Since `HttpClient` parses the 502 response successfully, it doesn't throw a `RuntimeException`, but the status mismatch fails the assertion.
- **Remediation**:
  - Update `testHttpClientPatchMethod` and `testHttpClientStripsSensitiveHeadersOnCrossOriginRedirect` to check if the status code is not 200, and if so, gracefully skip the test using `$this->markTestSkipped('External service httpbin.org is currently unavailable / returned status ' . $res['status'])` rather than failing.

## 2. PHPUnit 10+ "Mock Objects Without Expectations" Notices
- **Location**: Generated in 3 test suites:
  - `tests/Unit/UpdateServiceTest.php`
  - `tests/Service/DevicePairingServiceTest.php`
  - `tests/Security/SecurityRemediationTest.php`
- **Root Cause**:
  - These test suites use PHPUnit's mock builder (`$this->createMock()`) to create placeholder dependency objects but set no expectations on them (e.g. `$this->createMock(\OwnPay\Core\Database::class)` in security remediations test or `$this->createMock(\PDOStatement::class)` in device pairing test).
  - PHPUnit 10+ warns that mock objects with no configured expectations should either be stubs (`$this->createStub()`) or be annotated to opt out.
- **Remediation**:
  - Import the PHPUnit attribute `#[AllowMockObjectsWithoutExpectations]` in the 3 test classes and annotate them.
  - Namespace attribute: `PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations`.
