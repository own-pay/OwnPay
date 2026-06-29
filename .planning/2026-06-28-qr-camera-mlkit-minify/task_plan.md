# Task Plan - QR camera fix + R8 minify/shrink/optimize

Plan id: 2026-06-28-qr-camera-mlkit-minify
Owner: OwnPay Console (mobile-app/, git-ignored Flutter app)
Status: in_progress

## Goal

1. Fix the QR scanner: tapping "Scan QR" shows an error and the camera never opens.
   Symptoms seen so far: native NPE `Attempt to invoke virtual method 'k5.c k5.b.a(g5.c)'
   on a null object reference` (bare widget form) → after a rewrite, a generic
   "Could not start the scanner (genericError)".
2. Make the release build work WITH `minifyEnabled` + `shrinkResources` + R8 optimization
   (the user explicitly asked), without re-breaking the scanner.

## Root cause (see findings.md for the full trace)

- `mobile_scanner 7.2.0` defaults to the BUNDLED ML Kit model (`com.google.mlkit:barcode-scanning`).
- Native `MobileScanner.start()` calls `BarcodeScanning.getClient()` synchronously BEFORE binding
  the camera. On the user's device that call NPEs (obfuscated `k5/g5` = ML Kit internals) → the
  camera never opens. So the failure is ML Kit init, not CameraX, not permissions, not R8.
- My previous rewrite ALSO (a) double-started the controller (manual `start()` + widget autoStart)
  and (b) only displayed `errorCode.name`, hiding `errorDetails.message` - which is exactly where
  the real native message lives. So the UI concealed the ML Kit NPE behind "genericError".

## Decisions

- **D1 - Switch ML Kit bundled → unbundled** (`dev.steenbakker.mobile_scanner.useUnbundled=true`
  in gradle.properties) + add the `com.google.mlkit.vision.DEPENDENCIES=barcode` manifest meta-data.
  Rationale: the targeted fix for `getClient()` init failure; ML Kit comes from Google Play Services
  (already required - app ships on Play, uses the Play SMS exception). Inference stays 100% on-device,
  so data-sovereignty intent (no user-data egress) is preserved. Bonus: smaller APK.
  Documented exception per mobile CLAUDE.md §3 ("change only with a documented reason").
  Caveat for SIDELOAD testing: first scan needs network once (Play downloads the ~few-MB model).
- **D2 - Stop double-starting.** Use the canonical bare `MobileScanner(onDetect:, errorBuilder:)`
  form; the widget owns the controller, autostarts once, and handles app-lifecycle pause/resume.
- **D3 - Surface the real error.** errorBuilder shows `errorCode.message` AND `errorDetails.message`,
  distinguishes `permissionDenied` (offer "open settings"), and always offers manual entry
  (pairing always accepts a typed URL + code - fail-open for UX, the scanner is a convenience).
- **D4 - Enable R8** in release: `isMinifyEnabled=true`, `isShrinkResources=true`,
  `proguard-android-optimize.txt` + app `proguard-rules.pro` with ML Kit / CameraX / Flutter /
  app-native-channel keep rules so the optimized build does not strip the scanner.

## Phases

- [x] P1 investigate - read plugin Kotlin+Dart, manifest, gradle, pubspec. (complete)
- [x] P2 implement - gradle.properties, AndroidManifest, build.gradle.kts, proguard-rules.pro,
      qr_scan_screen.dart. (complete)
- [x] P3 verify - analyze clean · 129 tests green · `flutter build apk --split-per-abi --release`
      (R8 ON) succeeded → 16.6/19.0/20.4 MB. Camera open = device-boundary (user verifies). (complete)
- [x] P4 docs - HANDOFF.md (QR + APK-size notes rewritten, R8 marked done), memory file 8b,
      planning logs. (complete)

Status: COMPLETE on this machine; awaiting user on-device camera confirmation.

## Constraints (non-negotiable)

- No stubs/TODO/placeholder in delivered code. Never log SMS/tokens/keys/payloads.
- Money never a double (N/A here). HTTPS+cert-pin already done.
- End green on analyze + test; build apk when android/ changes (it does).
- Camera open + on-device scan = user verifies (no device here).
