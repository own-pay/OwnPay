#!/usr/bin/env bash
# mobile-e2e-emulator.sh — drives the OwnPay Console app on the ATD emulator.
#
# reactivecircus/android-emulator-runner@v2 executes its `script:` input line-by-line, each
# line as a separate `sh -c` process — multi-line shell (if/fi, loops) gets split into
# invalid commands. This driver exists so the emulator script is a single command:
#
#   script: bash $GITHUB_WORKSPACE/core/tools/mobile-e2e-emulator.sh
#
# It installs the debug APK, pairs the app against the ownpay stack on localhost:8080 (through
# `adb reverse`), completes the SMS disclosure, then exercises the SMS pipeline with
# `adb emu sms send`. Evidence (screenshots, uiautomator dumps, buffer/at-rest dumps, logcat) is
# written to /tmp/e2e. The pairing OTP is minted here, seconds before the pairing taps.
#
# Notes on emulator quirks (learned the hard way on the ATD image):
#  - /sdcard (emulated FUSE card) rejects shell writes with "Permission denied" for minutes after
#    boot — write everything to /data/local/tmp instead and pull from there.
#  - network toggles: `svc wifi disable` / `svc data disable` (and their `enable` counterparts).
#  - `am force-stop` puts the app in STOPPED state: manifest receivers stop firing until an
#    explicit relaunch. Do not force-stop before injecting SMS.
#  - `adb emu sms send` rejects senders that are not [+](0-9)* or [a-zA-Z0-9]{1,11}.
#  - Screenshot of a black framebuffer returns a ~15.5 KB uniform PNG (1080x2400); compare
#    byte size as a sanity check.
#
# Coordinates are for medium_phone (1080x2400 physical, density 420, logical 411x914dp).
# If stage screenshots show different positions, adjust the tapped coordinates here.

set -uo pipefail

SERVER_URL='http://localhost:8080'
EVID=/tmp/e2e
ADBDIR=/data/local/tmp/e2e
mkdir -p "$EVID" "$EVID/at-rest"
adb shell mkdir -p "$ADBDIR"

snap() { # snap <name>
  adb shell screencap -p "$ADBDIR/$1.png" >/dev/null 2>&1 && adb pull "$ADBDIR/$1.png" "$EVID/$1.png" >/dev/null 2>&1 \
    && echo "snap $1 ($(stat -c%s "$EVID/$1.png" 2>/dev/null || echo '?') bytes)" || echo "snap $1 FAILED"
}

uidmp() { # uidmp <name>
  adb shell uiautomator dump "$ADBDIR/$1.xml" >/dev/null 2>&1 && adb pull "$ADBDIR/$1.xml" "$EVID/$1.xml" >/dev/null 2>&1 || true
}

dismiss_anr() {
  # The ATD system_server/phone ANRs under load and pops a modal
  # "Process system isn't responding" dialog (android:id/aerr_wait, "Wait" button)
  # that takes focus and swallows every subsequent tap. Detect + tap Wait.
  uidmp ui-pre
  if grep -q 'aerr_wait' "$EVID/ui-pre.xml" 2>/dev/null; then
    local BOUNDS X1 Y1 X2 Y2
    BOUNDS=$(grep -oP 'resource-id="android:id/aerr_wait"[^>]*?bounds="\[\K[0-9]+,[0-9]+\]\[[0-9]+,[0-9]+' "$EVID/ui-pre.xml" | head -1 || true)
    if [ -n "$BOUNDS" ]; then
      X1=${BOUNDS%%,*}
      Y1=$(printf '%s' "$BOUNDS" | sed 's/^[0-9]*,\([0-9]*\)\].*/\1/')
      X2=$(printf '%s' "$BOUNDS" | sed 's/^[0-9]*,[0-9]*\]\[\([0-9]*\),.*/\1/')
      Y2=${BOUNDS##*,}
      echo "dismissing ANR dialog: tap $(( (X1 + X2) / 2 )) $(( (Y1 + Y2) / 2 ))"
      adb shell input tap $(( (X1 + X2) / 2 )) $(( (Y1 + Y2) / 2 ))
      sleep 5
    fi
  fi
}

hide_keyboard() { # hide the IME so the buttons below it become tappable again
  adb shell input keyevent KEYCODE_BACK
  sleep 2
}

tap() { # tap <x> <y> <label>
  echo "tap $3 ($1 $2)"
  adb shell input tap "$1" "$2"
  sleep 2
}
type_() { # type_ <text>
  echo "type_ $(printf '%s' "$1" | sed -E 's/[0-9]{6}/******/g')"
  adb shell input text "$1"
  sleep 1
}

echo "== wait for device =="
adb wait-for-device
adb devices -l
adb shell wm size
adb shell wm density

echo "== install APK =="
adb install /tmp/app-debug.apk
adb reverse tcp:8080 tcp:8080
adb shell pm grant org.ownpay.console android.permission.RECEIVE_SMS
adb shell pm grant org.ownpay.console android.permission.READ_SMS
adb shell pm grant org.ownpay.console android.permission.CAMERA

echo "== keep screen awake + unlock =="
adb shell svc power stayon true || true
adb shell settings put global screen_off_timeout 1800000 || true
adb shell input keyevent KEYCODE_WAKEUP
adb shell wm dismiss-keyguard || true

echo "== launch app =="
adb shell monkey -p org.ownpay.console -c android.intent.category.LAUNCHER 1
sleep 18
dismiss_anr
sleep 8

echo "== stage-0 (pre-pairing) =="
snap stage-0
adb shell pidof org.ownpay.console > "$EVID/focus.txt" || echo APP_NOT_RUNNING > "$EVID/focus.txt"

echo "== mint fresh pairing OTP (host-side core stack) =="
BASE=http://localhost:8080
CJ=/tmp/cj-e2e.txt
rm -f "$CJ"
TOKEN=$(curl -sf -c "$CJ" "$BASE/login" | grep -oP 'name="_csrf_token"\s+value="\K[^"]+' | head -1)
echo "login form csrf: ${TOKEN:+ok}${TOKEN:-MISSING}"
curl -s -o /dev/null -c "$CJ" -b "$CJ" -X POST "$BASE/login" \
  --data-urlencode "_csrf_token=$TOKEN" --data-urlencode "email=e2eadmin" --data-urlencode "password=Smashing!key-2026"
PAGE=$(curl -sf -c "$CJ" -b "$CJ" "$BASE/admin/devices")
TOKEN2=$(printf '%s' "$PAGE" | grep -oP 'name="_csrf_token"\s+value="\K[^"]+' | head -1)
echo "admin page csrf: ${TOKEN2:+ok}${TOKEN2:-MISSING}"
RESP=$(curl -sf -b "$CJ" -X POST "$BASE/admin/devices/generate-otp" -H "X-CSRF-Token: $TOKEN2" || true)
OTP=$(printf '%s' "$RESP" | grep -oP '"otp"\s*:\s*"\K[0-9]{6}' | head -1 || true)
if [ -z "$OTP" ]; then
  echo "OTP_MINT_FAILED: $(printf '%s' "$RESP" | head -c 300)"
  printf '%s' "$RESP" > "$EVID/otp-error.json"
  exit 0
fi
echo "OTP minted (300s TTL)"

tap 540 1205 "server URL field"
type_ "$SERVER_URL"
tap 540 1368 "OTP field"
type_ "$OTP"
snap stage-1
hide_keyboard
tap 540 1740 "Verify & Link"
sleep 6
dismiss_anr
snap stage-2
adb logcat -d -t 800 | grep -iE 'flutter|ownpay|exception|paired|device' > "$EVID/logcat-after-pair.txt" || true

# --- disclosure: allow SMS access (button fixed below the scroll view) ---
tap 540 2100 "Allow SMS Access"
sleep 6
dismiss_anr
snap stage-3

# --- at-rest: network OFF, inject whitelisted bKash SMS, dump private state ---
adb shell svc wifi disable || true
adb shell svc data disable || true
echo "== inject at-rest SMS (network down) =="
adb emu sms send bKash "You have received Tk 3,300.00 from 01612345678. Ref TrxID 8ATREST001"
sleep 6
adb shell run-as org.ownpay.console sh -c "cat shared_prefs/ownpay_sms_buffer.xml 2>/dev/null" > "$EVID/at-rest/sms_buffer.xml" || true
adb shell run-as org.ownpay.console sh -c "cd app_flutter && ls 2>/dev/null" | tr -d '\r' > /tmp/hive-listing.txt || true
while IFS= read -r f; do
  [ -z "$f" ] && continue
  adb shell run-as org.ownpay.console sh -c "cat \"app_flutter/$f\"" > "$EVID/at-rest/$f" 2>/dev/null || echo "at-rest copy failed: $f"
done < /tmp/hive-listing.txt
echo "== at-rest dump done =="; ls -la "$EVID/at-rest/"

# --- network ON, relaunch to trigger drain + sync ---
adb shell svc wifi enable || true
adb shell svc data enable || true
sleep 3
adb shell monkey -p org.ownpay.console -c android.intent.category.LAUNCHER 1 >/dev/null 2>&1
sleep 12
snap stage-4

echo "== live matrix =="
adb emu sms send bKash "You have received Tk 1,500.00 from 01812345678. Ref TrxID 8N7XYZ1234"
adb emu sms send MFSSupport "You have received Tk 9,999.00 from 01812345678. Ref TrxID BAD0000ZZZ"
adb emu sms send bKash "Your OTP is 123456. Never share it. Ref NOTE001"
sleep 25
snap stage-5

echo "== final dumps =="
adb logcat -d -t 6000 > "$EVID/logcat.txt" || true
adb shell dumpsys window | grep -iE 'mCurrentFocus|mFocusedApp' | head -5 > "$EVID/focus-final.txt" || true
echo "driver done"
exit 0
