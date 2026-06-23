# OwnPay Core - Detailed Audit Findings

## 1. Device Pairing Owner Fallback Privilege Escalation
- **Severity**: CRITICAL
- **File**: `src/Service/Device/DevicePairingService.php`
- **Lines**: L223-L238
- **Status**: UNMITIGATED
- **Code Reference**:
  ```php
        $createdBy = $valid['created_by'] ?? null;
        if ($createdBy !== null && $createdBy > 0) {
            $userId = $createdBy;
        } else {
            $sUserId = $_SESSION['auth_user_id'] ?? 0;
            $userId = is_scalar($sUserId) ? (int) $sUserId : 0;
            if ($userId === 0) {
                $db = $this->devices->getDatabase();
                $admin = $db->fetchOne(
                    "SELECT id FROM op_merchant_users WHERE merchant_id = :mid AND is_superadmin = 1 AND status = 'active' ORDER BY id ASC LIMIT 1",
                    ['mid' => $merchantId]
                );
                $adminIdVal = $admin['id'] ?? 1;
                $userId = is_scalar($adminIdVal) ? (int) $adminIdVal : 1;
            }
        }
  ```
- **Execution Chain**: `Admin\DeviceController::generateOtp` is called (which leaves `$adminId` as null) -> API `MobileController::pairDevice` is hit without session state -> `DevicePairingService::pairDevice` attempts to resolve user -> falls back to first superadmin or system user 1.
- **Vulnerability Scenario**: An authenticated staff member with low privileges but with permission to generate pairing OTPs uses the admin dashboard to generate an OTP. They then use this OTP to pair a companion device via the mobile API. Because `DeviceController::generateOtp` omits the staff member's ID, the OTP record is saved with a null `created_by`. During pairing over the stateless API, there is no session, so the fallback logic blindly assigns the JWT access token to the brand's first superadmin (or system user 1 if none exists).
- **Proof of Concept**:
  1. Low-privileged user generates OTP via UI.
  2. Submits OTP to `POST /api/v1/mobile/pair`.
  3. Decodes the returned JWT `access_token` and inspects the `sub` claim. It will match the ID of a super administrator.
  4. Attacker uses the token to perform superadmin actions via the API.
- **Recommended Mitigation**: Pass the `$_SESSION['auth_user_id']` explicitly in `DeviceController::generateOtp($mid, $adminId)`. Furthermore, remove the fallback logic from `DevicePairingService::pairDevice`; if the initiating user context cannot be reliably determined, the pairing attempt must explicitly fail.

## 2. Sandbox/Fallback Verification Anti-Pattern
- **Severity**: HIGH
- **File**: `modules/gateways/easypaisa/EasypaisaGateway.php`
- **Lines**: L132-L136
- **Status**: UNMITIGATED
- **Code Reference**:
  ```php
        } else {
            // Fallback for sandbox / testing when hash key is not configured and mode is sandbox
            $mode = $this->getString($credentials['mode'] ?? 'sandbox');
            $hashValid = ($mode === 'sandbox');
        }
  ```
- **Execution Chain**: External Gateway Webhook/Callback -> `UnifiedWebhookController` -> `GatewayBridge::verifyWebhookSignature` (or direct verify) -> `EasypaisaGateway::verify`.
- **Vulnerability Scenario**: If a merchant configures their Easypaisa gateway in the 'sandbox' mode but omits the Hash Key configuration (or leaves it blank), the gateway adapter bypasses cryptographic signature checks entirely. A malicious actor observing the URL can trigger the callback endpoint manually with arbitrary order references, and the system will artificially accept it.
- **Proof of Concept**:
  1. Target a merchant with Easypaisa enabled in sandbox mode and no hash key.
  2. Send an HTTP POST to the return URL with `orderRefNum=VALID_PENDING_TRX` and an arbitrary/empty `secureHash`.
  3. The gateway simulator accepts the invalid hash and marks the transaction as `completed`.
- **Recommended Mitigation**: Payment validation logic should never "simulate" success simply because keys are missing, even in sandbox mode. The adapter should strictly verify signatures using test sandbox keys provided by the processor. Remove the fallback logic.

## 3. Invoice Item Race Condition (0.00 BDT Override)
- **Severity**: HIGH
- **File**: `src/Service/Payment/InvoiceService.php`
- **Lines**: L267-L286
- **Status**: UNMITIGATED
- **Code Reference**:
  ```php
        // Delete old items
        $this->db->execute(
            "DELETE FROM op_invoice_items WHERE invoice_id = :id",
            ['id' => $id]
        );

        // Insert new/updated items
        foreach ($items as $i => $item) {
            $this->db->insert(
                "INSERT INTO op_invoice_items (invoice_id, description, quantity, unit_price, total, sort_order) VALUES (:inv, :desc, :qty, :price, :total, :sort)",
  ```
- **Execution Chain**: `InvoiceController::update` -> `InvoiceService::update`
- **Vulnerability Scenario**: When an invoice is updated, the service calculates new totals, commits an `UPDATE` to the main invoice record, deletes all existing line items, and inserts the new ones via a loop. These operations are not wrapped in a database transaction (`$this->db->transaction`). If the system crashes during the item insertion loop, the invoice is left permanently corrupted without line items. Furthermore, if the request payload omits the items array, the system effectively wipes all items and overwrites the invoice total to `0.00`.
- **Proof of Concept**:
  1. Submit a `PUT /admin/invoices/update/{id}` request with an empty or missing `items` array.
  2. The service deletes all line items and successfully commits the `$total = '0.00'` value to the invoice record.
- **Recommended Mitigation**: Wrap the entire `update` scope (including the main `op_invoices` update, line item deletions, and new line item insertions) inside a `$this->db->transaction` callback. Add a sanity check to reject updates that result in an empty line item list.
