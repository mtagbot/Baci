#!/usr/bin/env bash
# اجرای همهٔ تست‌ها با یک دستور.
#
#   bash scripts/run-tests.sh
#
# متغیرهای اختیاری:
#   SITE=.arena/current/SchoolDeskPro/www   سورس دیگری را تست کن
#   PATCH=update-v4.130.0                   وصله را قبل از بسته‌بندی تست کن
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO/tests" || exit 1

if [ ! -d node_modules ]; then
  echo ">>> نصب وابستگی‌ها (فقط بار اول)…"
  npm install --silent --no-audit --no-fund || { echo "❌ npm install ناموفق بود"; exit 1; }
fi

declare -a NAMES=("بررسی نحوی جاوااسکریپت" "بررسی نحوی PHP" "منطق تایمر" "انتخاب گزینه" "موقعیت مکانی" "راندن کامل آزمون" "قفل تک‌دستگاهی" "تختهٔ سفید و چیدمان" "حریم خصوصی گزارش" "رابط مانیتورینگ" "سخت‌سازی v4.131.0" "کاشی‌های هدر و کپچای تطبیقی" "هدر موبایل و تبلت" "آیکون کاشی‌ها" "پوستهٔ دسکتاپ")
declare -a CMDS=("node test-js-syntax.mjs" "node lint.mjs" "node test-timer.mjs" "node test-save.mjs" "node test-location.mjs" "node verify.mjs" "node test-device-lock.mjs" "node test-whiteboard.mjs" "node test-proctoring-privacy.mjs" "node test-monitor-ui.mjs" "node test-hardening.mjs" "node test-header-tiles.mjs" "node test-header-mobile.mjs" "node test-tile-icons.mjs" "node test-desk-shell.mjs")
declare -a RC=()

for i in "${!CMDS[@]}"; do
  echo
  echo "╔══════════════════════════════════════════╗"
  echo "║  ${NAMES[$i]}"
  echo "╚══════════════════════════════════════════╝"
  eval "${CMDS[$i]}"
  RC+=($?)
done

echo
echo "════════════════ خلاصه ════════════════"
FAIL=0
for i in "${!CMDS[@]}"; do
  if [ "${RC[$i]}" -eq 0 ]; then
    printf "  ✅ %s\n" "${NAMES[$i]}"
  else
    printf "  ❌ %s\n" "${NAMES[$i]}"; FAIL=1
  fi
done
echo "═══════════════════════════════════════"
[ "$FAIL" -eq 0 ] && echo "  همهٔ سوئیت‌ها سبز" || echo "  دست‌کم یک سوئیت قرمز است"
exit "$FAIL"
