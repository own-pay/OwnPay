# OwnPay Mobile Companion App — Security Notes

The Android companion app captures SMS notifications from MFS/bank senders, gates them **on the
device**, encrypts them with a per-device AES key, and syncs them to the server for verification.

## How to build

Requires JDK 21 and the Android SDK. Point Flutter at your SDK:

```bash
flutter config --android-sdk /path/to/android-sdk
cd OwnPay-Mobile-App
flutter pub get
flutter test
flutter build apk --release --target-platform android-arm64
```

Release builds are signed with the upload keystore referenced by `android/keystore.properties`
(never committed). Development builds use the debug keystore automatically.

App targets **Android 17 (API 37)**.

## TLS trust model: standard vs pin mode

The app ships in one of two TLS modes:

- **Standard mode (default):** the server certificate is validated by the system trust store with
  full hostname checking - exactly what a browser does. Renewing your server certificate with any
  publicly trusted CA (Let's Encrypt, Sectigo, etc.) just works forever. Nothing to configure.

- **Pin mode (self-signed / private-CA servers):** the server's certificate is not publicly trusted,
  so the app pins the server's **Subject Public Key Info** (SPKI) instead of the certificate itself.
  Pinning SPKI means your server does not have to change its certificate before every renewal — as
  long as the same key is reused, the pin holds.

### Enabling pin mode

Pin mode is entered during pairing, and only from the pairing QR:

1. Server-side, the operator sets `TLS_CERT_PATH` in `.env` (or the container environment) to the
   absolute path of the server's TLS certificate PEM. Example (nginx):
   `ssl_certificate /etc/ssl/private/tls.crt;`
   `TLS_CERT_PATH=/etc/ssl/private/tls.crt`
2. Admin device pairing screen generates the QR as usual; if `TLS_CERT_PATH` points to a valid PEM
   the payload includes `"spki":"<sha256-hex>"`.
3. The user scans the QR; the fingerprint is displayed; confirm to pin.
4. From then on the app refuses any certificate from that server whose public key hash does not
   match the pin — hard fail, and a clear recovery message directs the user to re-pair instead of
   bypassing validation.
5. To rotate the server's key: after re-pairing with the new QR, the pin is refreshed. If the cert
   is replaced without re-pairing, the app cannot connect and the settings screen offers
   **Reset connection & re-pair**. The queue and audit log stay intact; serverside data does not.

If `TLS_CERT_PATH` is not set (or unreadable), the QR carries only `server_url` and `otp`,
pinning is skipped, and the app uses standard mode. Pin mode cannot be enabled on demand from the
phone - the server must advertise the fingerprint, otherwise the app has no authenticated channel
to learn it.

### Recovering from a changed certificate

- **Standard mode:** refresh the server cert or hostname; no client action needed.
- **Pin mode:** the app refuses the connection. Open Settings → **Reset connection & re-pair**, then
  scan the QR from the admin panel again. All app-local data (queued SMS state, audit log,
  settings) is preserved. If you need to revoke and re-pair from scratch, use
  **Revoke & wipe this device** instead (this is a full wipe, not a pin reset).

## SMS capture & privacy

- A background service and manifest-declared receiver capture SMS **even when the app is killed**.
- Every captured message is appended to an on-disk buffer **encrypted at rest** (AES-256-GCM with a
  non-extractable Android Keystore key) before it reaches the Flutter layer.
- The on-device privacy gate runs against the server's whitelist (`/api/mobile/v1/config/filter-rules`).
  Messages from senders not in the whitelist are dropped at the native layer and never persisted.
- On overflow or whitelist drops the app surfaces a visible warning and logs a counter — silent loss
  is not possible.
- SMS bodies leave the device **only** for senders on the whitelist, encrypted with the per-device
  AES key, and even then only for the purposes of payment verification.

## Offline queue

Unsent verifications are held in an on-device encrypted queue and retried with exponential backoff.
Approved SMS are drained oldest-first, in batches. Duplicate submissions are server-side idempotent;
every receipt is re-checkable from the audit log on the device.

## Root / repackaged builds

Release builds are signed with the production upload key; debug builds are never accepted as release
APKs by the distribution channels (Play Store, internal track). If you distribute outside a store,
verify the signing certificate with `apksigner verify --print-certs ownpay.apk`.
