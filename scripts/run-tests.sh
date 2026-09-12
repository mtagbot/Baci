#!/usr/bin/env bash
# اجرای همهٔ تست‌ها با یک دستور.
#
#   bash scripts/run-tests.sh
#
# متغیرهای اختیاری:
#   SITE=.arena/current/SchoolDeskPro/www   سورس دیگری را تست کن
#   PATCH=update-v4.127.0                   وصله را قبل از بسته‌بندی تست کن
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO/tests" || exit 1

if [ ! -d node_modules ]; then
  echo ">>> نصب وابستگی‌ها (فقط بار اول)…"
  npm install --silent --no-audit --no-fund || { echo "❌ npm install ناموفق بود"; exit 1; }
fi

declare -a NAMES=("بررسی نحوی PHP" "منطق تایمر" "انتخاب گزینه" "راندن کامل آزمون")
declare -a CMDS=("node lint.mjs" "node test-timer.mjs" "node test-save.mjs" "node verify.mjs")
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
