#!/usr/bin/env bash
# mobile-e2e-emulator.sh — drives the OwnPay Console app on the ATD emulator.
#
# reactivecircus/android-emulator-runner@v2 executes its `script:` input line-by-line, each
# line as a separate `sh -c` process — multi-line shell (if/fi, loops) gets split into
# invalid commands. This driver exists so the emulator script is a single command:
#
#   script: bash $GITHUB_WORKSPACE/core/tools/mobile-e2e-emulator.sh
#
# It installs the debug APK, pairs the app against the ownpay stack running on localhost:8080
# (through `adb reverse`), completes the SMS disclosure, then exercises the SMS pipeline with
# `adb emu sms send`. Evidence (screenshots, uiautomator dumps, buffer/logcat dumps) is written
# to /tmp/e2e for the workflow's artifact upload step. The pairing OTP is minted here, seconds
# before the pairing taps, because the OTP TTL is 300s and the emulator boot itself takes far
# longer than that.
#
# Coordinates for the pairing screen are for medium_phone (1080x2400 physical, density 420,
# logical 411x914dp). If this script's screenshots show different positions, adjust them here.

set -uo pipefail

EXE=1                       # set to 0 to only diagnose (no taps, no SMS sends)
SERVER_URL='http://localhost:8080'
CORE_DIR="${GITHUB_WORKSPACE:-/home/runner/work/OwnPay/OwnPay}/core"
EVID=/tmp/e2e
mkdir -p "$EVID"

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

dismiss_anr() {
  # The ATD system_server/phone process ANRs under load and pops a modal
  # "Process system isn't responding" dialog (android:id/aerr_wait, "Wait" button)
  # which takes focus and swallows every subsequent tap. Detect and dismiss it.
  adb shell uiautomator dump /sdcard/ui-pre.xml >/dev/null 2>&1 || true
  adb pull /sdcard/ui-pre.xml "$EVID/ui-pre.xml" >/dev/null 2>&1 || return 0
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
dismiss_anr
sleep 8

echo "== capture stage-0 (pre-pairing) =="
adb shell screencap -p /sdcard/stage-0.png && adb pull /sdcard/stage-0.png "$EVID/stage-0.png" || true
adb shell dumpsys window | grep -iE 'mCurrentFocus|mFocusedApp' | head -5 > "$EVID/focus.txt" || true
adb shell pidof org.ownpay.console >> "$EVID/focus.txt" || echo APP_NOT_RUNNING >> "$EVID/focus.txt"

# --- taps -----------------------------------------------------------------
tap() { # tap <x> <y> <label>
  echo "tap $3 ($1 $2)"
  adb shell input tap "$1" "$2"
  sleep 2
}
type_() { # type_ <text>
  echo "type_ $(printf '%s' "$1" | sed -E 's/[0-9]{6}/******/g')"
  adb shell input text "$1"
}
if [ "$EXE" = "1" ]; then
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
    echo "$RESP" > "$EVID/otp-error.json"
  else
    echo "OTP minted (300s TTL)"
    tap 540 1205 "server URL field"
    type_ "$SERVER_URL"
    tap 540 1368 "OTP field"
    type_ "$OTP"
    adb shell screencap -p /sdcard/stage-1.png && adb pull /sdcard/stage-1.png "$EVID/stage-1.png" || true
    tap 540 1740 "Verify & Link"
    sleep 6
    dismiss_anr
    adb shell screencap -p /sdcard/stage-2.png && adb pull /sdcard/stage-2.png "$EVID/stage-2.png" || true

    # --- disclosure: allow SMS access ---
    tap 540 2100 "Allow SMS Access"
    sleep 4
    dismiss_anr
    adb shell screencap -p /sdcard/stage-3.png && adb pull /sdcard/stage-3.png "$EVID/stage-3.png" || true

    # --- at-rest proof: force-stop, inject, read the buffer ---
    adb shell am force-stop org.ownpay.console
    adb emu sms send bKash "You have received Tk 3,300.00 from 01612345678. Ref TrxID 8ATREST001"
    sleep 3
    adb shell run-as org.ownpay.console cat /data/data/org.ownpay.console/shared_prefs/ownpay_sms_buffer.xml > "$EVID/buffer.xml" 2>&1 || true

    # --- relaunch: drain + sync should forward the buffered SMS ---
    adb shell monkey -p org.ownpay.console -c android.intent.category.LAUNCHER 1 >/dev/null 2>&1
    sleep 12

    # --- live matrix ---
    adb emu sms send bKash "You have received Tk 1,500.00 from 01812345678. Ref TrxID 8N7XYZ1234"
    adb emu sms send TotallFakeSms "You have received Tk 9,999.00 from 01812345678. Ref TrxID FAKE999ZZZ"
    adb emu sms send bKash "Your OTP is 123456. Never share it. Ref NOTE001"
    sleep 25
    adb shell screencap -p /sdcard/stage-4.png && adb pull /sdcard/stage-4.png "$EVID/stage-4.png" || true
  fi
fi

echo "== final dumps =="
adb logcat -d -t 2000 > "$EVID/logcat.txt" || true
adb shell dumpsys window | grep -iE 'mCurrentFocus|mFocusedApp' | head -5 > "$EVID/focus-final.txt" || true
echo "driver done"
exit 0
