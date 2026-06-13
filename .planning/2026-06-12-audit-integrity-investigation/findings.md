# Findings & Decisions: Audit Integrity Investigation

## Requirements
- Investigate the "Database Tampering Detected!" alert with 13 corrupted audit log entries.
- Explain the meaning of the alert and the root cause to the user.

## Research Findings
1. **Compromised Log Rows**: The verification scan identifies 13 compromised audit logs (IDs: 1, 2, 3, 13, 15, 16, 17, 20, 21, 22, 23, 27, 28).
2. **Analysis of Fields**: Every single compromised row contains non-null JSON values in `new_values` (e.g. `{"email": "admin"}` or `{"name": "Devoart"}`). Every single valid row has `old_values = NULL` and `new_values = NULL`.
3. **Root Cause**:
   - During log creation, PHP generates compact JSON (`{"email":"admin"}` with no whitespace) and hashes it to calculate the stored signature.
   - The database column is of type `JSON`. When MySQL receives this string, it automatically normalizes and formats it, adding a space after colons (`{"email": "admin"}`).
   - During the integrity scan, PHP fetches the formatted JSON string from the database and hashes it. Because the whitespace differs, the calculated SHA-256 HMAC signature does not match the stored signature.
4. **Conclusion**: This is a **false positive** caused by database-level JSON formatting normalization. **No actual database tampering occurred**.

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| Standardize JSON payload before hashing | Decoding and re-encoding JSON payloads compactly prior to signature calculation guarantees consistent hashing regardless of database formatting. |
