# Progress log - QR camera + R8

## 2026-06-28

- P1 investigate (complete):
  - Read mobile_scanner 7.2.0 android build.gradle (useUnbundled flag, default bundled),
    proguard-rules.pro (consumer ML Kit keeps, but `com.google.mlkit.*` only - not `**`),
    MobileScanner.kt (getClient() before camera bind; resolutionInfo!! hazard),
    MobileScannerHandler.kt (exception→code mapping; else→GENERIC_ERROR),
    mobile_scanner.dart widget (autoStart double-start; default error UI shows errorDetails),
    method_channel start (errorDetails.message carries native message).
  - Read app: build.gradle.kts (no R8), gradle.properties (no useUnbundled), AndroidManifest
    (no ML Kit DEPENDENCIES meta-data), MainActivity (FlutterActivity ✓), pairing_screen (contract).
  - Root cause: bundled ML Kit `getClient()` NPE on device; my UI hid `errorDetails.message`.
- P2 implement (complete):
  - gradle.properties: `dev.steenbakker.mobile_scanner.useUnbundled=true`.
  - AndroidManifest: added `com.google.mlkit.vision.DEPENDENCIES=barcode` meta-data.
  - build.gradle.kts release: `isMinifyEnabled=true`, `isShrinkResources=true`,
    `proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")`;
    reworded the signing comment off "TODO".
  - NEW android/app/proguard-rules.pro: ML Kit / CameraX / Flutter / flutter_local_notifications
    (Gson) / org.ownpay.console.** keeps + enum/native/dontwarn safety.
  - qr_scan_screen.dart: rewritten to the bare `MobileScanner(onDetect:, errorBuilder:)` form
    (widget owns controller → single auto-start, widget lifecycle). errorBuilder now shows
    errorCode.message + errorDetails.message, special-cases permissionDenied (Open settings),
    always offers manual entry. Contract preserved (pops {server_url, otp}).
- P3 verify (complete):
  - `flutter analyze` → No issues found (42s).
  - `flutter test` → All tests passed! (129).
  - `flutter build apk --split-per-abi --release` (R8 ON) → SUCCESS.
    Sizes: v7a 16.6MB (was 20.1) · arm64 19.0MB (was 24.2) · x86_64 20.4MB (was 26.5).
    Camera-open + on-device scan = user must verify (no device here); errorBuilder now reveals
    the real native message if it still fails.
- P4 docs: HANDOFF.md + ROADMAP note + memory (in progress).
