# Findings - QR camera failure + R8

## Versions / config (verified)

- `mobile_scanner: 7.2.0` (pubspec.lock). Plugin android/build.gradle:
  `def useUnbundled = findProperty('dev.steenbakker.mobile_scanner.useUnbundled') ?: false`
  → false ⇒ BUNDLED `com.google.mlkit:barcode-scanning:17.3.0`; true ⇒
  `com.google.android.gms:play-services-mlkit-barcode-scanning:18.3.1`.
  Also pulls `androidx.camera:camera-camera2/-lifecycle:1.5.3`.
- `android/gradle.properties`: does NOT set useUnbundled ⇒ currently bundled.
- `android/app/build.gradle.kts` release: NO `isMinifyEnabled` / `isShrinkResources`
  ⇒ R8 currently OFF. So the camera failure is NOT an R8-stripping problem.
- `AndroidManifest.xml`: has CAMERA perm + `<uses-feature camera required=false>`. MISSING the
  ML Kit `com.google.mlkit.vision.DEPENDENCIES` meta-data (only matters for the unbundled variant).
- `MainActivity` extends `FlutterActivity` ⇒ a valid LifecycleOwner (rules out the
  `activity as LifecycleOwner` ClassCastException → NoCamera path).

## The failure trace (plugin source, mobile_scanner 7.2.0)

- `android/.../MobileScanner.kt::start()`:
  - line ~397: `scanner = barcodeScannerFactory(opts)` = `BarcodeScanning.getClient(...)`,
    **synchronous, BEFORE** the camera is bound. If ML Kit init is broken this NPEs here.
  - the try/catch only wraps `bindToLifecycle` (≈445–457 → `NoCamera`); getClient() is outside it.
  - line ~491 `analysis.resolutionInfo!!.resolution` is a separate latent `!!` NPE hazard (timing),
    but it is AFTER getClient(); our symptom ("camera never opens") matches the earlier getClient().
- `MobileScannerHandler.kt::start` maps native exceptions: AlreadyStarted/CameraError/NoCamera/
  else→GENERIC_ERROR. A synchronous throw in `onMethodCall` → Flutter returns a PlatformException
  carrying the throwable message.
- Dart `method_channel/mobile_scanner_method_channel.dart::start` (≈370): on PlatformException →
  `MobileScannerException(errorCode: fromPlatformException(error), errorDetails:{code, details,
  message: error.message})`. So the **real native message lands in `errorDetails.message`**.
- Dart widget `mobile_scanner.dart`:
  - `_initializeController` (≈208): `if (controller.autoStart) await controller.start();`
    ⇒ passing a controller AND calling start() in initState = DOUBLE START (my prior bug).
  - default error UI (≈336–341) prints BOTH `errorCode.message` and `errorDetails.message` -
    which is why the bare form originally showed the raw `k5.b.a` NPE, while my custom
    `_ScanError` printed only `errorCode.name` ("genericError") and hid the cause.

## Conclusion

- Obfuscated `k5`/`g5` ⇒ ML Kit internals (AndroidX/CameraX ship un-obfuscated). The bundled
  barcode model's `getClient()` fails to initialize on this device.
- Fix = unbundled ML Kit (Play Services) + DEPENDENCIES meta-data. Stop double-start, surface
  `errorDetails.message`, keep a manual-entry fallback. Enable R8 with ML Kit/CameraX keep rules.

## Contracts to preserve

- `pairing_screen.dart::_scan()` pushes `QrScanScreen` and expects `Navigator.pop`
  `Map<String,String>{server_url, otp}` (null = cancelled). Keep this exactly.
- QR payload parsed from `jsonDecode(raw)` → `{server_url, otp}` (both String).
- No QR widget test exists (camera UI). Verification = analyze + test(129) + release build.

## R8 keep targets (proguard-rules.pro)

ML Kit (`com.google.mlkit.**`, `com.google.android.gms.internal.mlkit_vision_barcode.**`),
barhopper, CameraX (`androidx.camera.**`), Flutter embedding, app native classes
(`org.ownpay.console.**` - manifest-referenced receivers/services), enum members; `-dontwarn`
for gms/mlkit/coroutines. Most plugins already ship consumer rules; ML Kit is the real risk.
