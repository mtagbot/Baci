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

declare -a NAMES=("بررسی نحوی جاوااسکریپت" "بررسی نحوی PHP" "منطق تایمر" "انتخاب گزینه" "موقعیت مکانی" "راندن کامل آزمون" "قفل تک‌دستگاهی" "تختهٔ سفید و چیدمان" "حریم خصوصی گزارش" "رابط مانیتورینگ" "سخت‌سازی v4.131.0" "کاشی‌های هدر و کپچای تطبیقی" "هدر موبایل و تبلت" "آیکون کاشی‌ها" "پوستهٔ دسکتاپ" "تگ‌ها، جستجو و رنگ‌بندی" "کارت ورود دانش‌آموز" "لیست کلاسی Word" "لیست کل مدرسه" "اسکنر: دوربین و درخواست‌ها" "اسکنر: صفحه و بازگشت" "تکرار متوالی چاپ تگ و کارت" "برنامه هفتگی و آزمون کلاسی دبیر" "گروه آزمون کلاسی و استثناها" "برنامه شش‌روزه و عملیات آزمون کلاسی" "تأیید رمز مدیر و لغو واقعی نشست" "هم‌خوانی چاپ فیزیکی کارت" "طرح سفارشی کارت ورود" "آپلود لوگو و لبهٔ تمیز کارت" "اصلاحات ورود، فونت، رنگ و چاپ" "رندر سریع، پیام ورود و وضعیت اتصال" "به‌روزرسانی آنلاین دسکتاپ" "تگ آزمایشی: چاپ، اسکن و پیام فقط برای مدیریت" "ورود مدیر از داخل ربات" "صدای اسکنر: buzzer، حضور، تأخیر و خطای شبکه" "ویرایشگر آزمون: موبایل و تبلت")
declare -a CMDS=("node test-js-syntax.mjs" "node lint.mjs" "node test-timer.mjs" "node test-save.mjs" "node test-location.mjs" "node verify.mjs" "node test-device-lock.mjs" "node test-whiteboard.mjs" "node test-proctoring-privacy.mjs" "node test-monitor-ui.mjs" "node test-hardening.mjs" "node test-header-tiles.mjs" "node test-header-mobile.mjs" "node test-tile-icons.mjs" "node test-desk-shell.mjs" "node test-tags-select-theme.mjs" "node test-entry-cards.mjs" "node test-class-list-docx.mjs" "node test-school-list.mjs" "node test-scanner-lifecycle.mjs" "node test-scanner-page.mjs" "node test-print-copies.mjs" "node test-staff-workflows.mjs" "node test-class-exam-groups.mjs" "node test-class-exam-actions.mjs" "node test-session-security.mjs" "node test-card-print-layout.mjs" "node test-custom-card.mjs" "node test-card-logo.mjs" "node test-quality-improvements.mjs" "node test-fast-ui.mjs" "node test-desk-update.mjs" "node test-attendance-test-tag.mjs" "node test-bot-admin-login.mjs" "node test-scanner-sounds.mjs" "node test-exam-designer-mobile.mjs")
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
