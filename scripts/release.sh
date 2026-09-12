#!/usr/bin/env bash
# بسته‌بندی کامل یک انتشار با یک دستور.
#
#   bash scripts/release.sh update-v4.127.0 4.127.0 2.58.0
#
# چه کار می‌کند:
#   ۱) MODIFIED-FILES-v<sit>.zip را از پوشهٔ وصله می‌سازد و unzip -t می‌گیرد
#   ۲) جدیدترین بستهٔ دسکتاپ را باز می‌کند، فایل‌های وصله را جایگزین می‌کند،
#      نسخه را در README.txt بالا می‌برد و بستهٔ جدید را می‌سازد
#   ۳) شمار ورودی‌های دو بسته را مقایسه می‌کند و diff فهرست را نشان می‌دهد
#      (تا ثابت شود فقط فایل‌های قصدشده عوض شده‌اند)
#   ۴) SHA-256 هر دو zip را چاپ می‌کند
#
# پیش‌نیاز: پوشهٔ وصله باید راهنمای-بروزرسانی.txt داشته باشد.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO"

PATCH_DIR="${1:?用法: release.sh <update-vX.Y.Z> <site-version> <desktop-version>}"
SITE_VER="${2:?نسخهٔ سایت لازم است (مثلاً 4.127.0)}"
DESK_VER="${3:?نسخهٔ دسکتاپ لازم است (مثلاً 2.58.0)}"

[ -d "$PATCH_DIR" ] || { echo "❌ پوشهٔ $PATCH_DIR پیدا نشد"; exit 1; }
[ -f "$PATCH_DIR/راهنمای-بروزرسانی.txt" ] || { echo "❌ راهنمای-بروزرسانی.txt در $PATCH_DIR نیست"; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
# مهر زمانی یکسان = زیپ قابل‌تولیدِ مجدد، یعنی SHA-256 در دو ساخت مختلف یکی
# می‌شود و راستی‌آزمایی دانلود معنادار می‌ماند.
FIXED_TS='2026-01-01 00:00:00'

echo "════ ۱) زیپ فایل‌های سایت ════"
SITE_ZIP="MODIFIED-FILES-v${SITE_VER}.zip"
rm -f "$SITE_ZIP"
cp -a "$PATCH_DIR" "$WORK/"
find "$WORK/$(basename "$PATCH_DIR")" -exec touch -d "$FIXED_TS" {} +
(cd "$WORK" && zip -qryX "$REPO/$SITE_ZIP" "$(basename "$PATCH_DIR")")
unzip -tq "$SITE_ZIP" >/dev/null && echo "  ✅ $SITE_ZIP  ($(stat -c%s "$SITE_ZIP") بایت)"

echo
echo "════ ۲) بستهٔ دسکتاپ ════"
PREV="$(ls -1 SchoolDeskPro-v*-win64.zip 2>/dev/null | sort -V | tail -1)"
[ -n "$PREV" ] || { echo "❌ بستهٔ دسکتاپ قبلی پیدا نشد"; exit 1; }
echo "  پایه: $PREV"

unzip -qo "$PREV" -d "$WORK"
BASE="$WORK/SchoolDeskPro"

# جایگزینی فایل‌های وصله در www/ با حفظ زیرپوشه‌ها
COUNT=0
while IFS= read -r -d '' f; do
  rel="${f#$PATCH_DIR/}"
  mkdir -p "$BASE/www/$(dirname "$rel")"
  cp "$f" "$BASE/www/$rel"
  echo "    · www/$rel"
  COUNT=$((COUNT+1))
done < <(find "$PATCH_DIR" -name "*.php" -print0)
[ "$COUNT" -gt 0 ] || { echo "❌ هیچ فایل PHP در پوشهٔ وصله نیست"; exit 1; }

# bump نسخه در README.txt
python3 - "$BASE/README.txt" "$DESK_VER" <<'PY'
import io, re, sys
p, ver = sys.argv[1], sys.argv[2]
s = io.open(p, encoding='utf-8').read()
s2, n = re.subn(r'(^\s*نسخه\s+)\d+\.\d+\.\d+', r'\g<1>' + ver, s, count=1, flags=re.M)
if n == 0:
    print('  ⚠️  خط نسخه در README.txt پیدا نشد — عوض نشد'); sys.exit(0)
io.open(p, 'w', encoding='utf-8').write(s2)
print(f'  ✅ README.txt → نسخه {ver}')
PY

DESK_ZIP="SchoolDeskPro-v${DESK_VER}-win64.zip"
rm -f "$DESK_ZIP"
find "$WORK/SchoolDeskPro" -exec touch -d "$FIXED_TS" {} +
(cd "$WORK" && zip -qryX "$REPO/$DESK_ZIP" SchoolDeskPro)
unzip -tq "$DESK_ZIP" >/dev/null && echo "  ✅ $DESK_ZIP  ($(stat -c%s "$DESK_ZIP") بایت)"

echo
echo "════ ۳) کنترل اختلاف دو بسته ════"
for v in "$PREV" "$DESK_ZIP"; do
  unzip -l "$v" | awk 'NR>3 && NF>=4 {print $4, $1}' | sort > "$WORK/$(basename "$v").txt"
done
N1=$(wc -l < "$WORK/$(basename "$PREV").txt")
N2=$(wc -l < "$WORK/$(basename "$DESK_ZIP").txt")
echo "  ورودی‌ها: $N1 → $N2"
[ "$N1" -eq "$N2" ] && echo "  ✅ شمار ورودی‌ها برابر است" || echo "  ⚠️  شمار ورودی‌ها عوض شده — بررسی کنید"
echo "  فایل‌های متفاوت:"
# توجه: grep بی‌نتیجه کد ۱ می‌دهد و با set -o pipefail کل اسکریپت را می‌بندد
DIFF="$(diff "$WORK/$(basename "$PREV").txt" "$WORK/$(basename "$DESK_ZIP").txt" | grep -E '^[<>]' | sort -k2 || true)"
if [ -z "$DIFF" ]; then
  echo "    (هیچ — فقط محتوای فایل‌های قصدشده عوض شده)"
else
  echo "$DIFF" | sed 's/^/    /'
fi

echo
echo "════ ۴) SHA-256 ════"
sha256sum "$SITE_ZIP" "$DESK_ZIP" | sed 's/^/  /'
echo
echo "✅ انتشار v${SITE_VER} سایت / v${DESK_VER} دسکتاپ آماده است."
echo "   مرحلهٔ بعد: git add + commit + push، سپس بالا آوردن سرور دانلود."
