/* ══════════════════════════════════════════════════════════════════
   کارت ورود دانش‌آموز — v4.136.0
   ابعاد ISO/IEC 7810 ID-1: ۸۵٫۶ × ۵۴ میلی‌متر (کارت بانکی/کارت ملی)

   این فایل هم در پیش‌نمایش صفحه و هم در نمای چاپ include می‌شود، تا
   آنچه کاربر می‌بیند دقیقاً همان چیزی باشد که چاپ می‌شود. (اگر دو
   نسخهٔ جدا می‌نوشتیم، همان دام «پیش‌نمایش درست، چاپ غلط» تکرار
   می‌شد که در صفحهٔ تگ‌ها گرفتیمش.)
   ══════════════════════════════════════════════════════════════════ */
.card-id{
  position:relative;
  width:85.6mm; height:54mm;
  display:inline-block; vertical-align:top;
  margin:0 0 var(--gap,4mm) var(--gap,4mm);
  background:#fff;
  border-radius:3mm;
  overflow:hidden;
  page-break-inside:avoid; break-inside:avoid;
  font-size:0; line-height:0;
  color:#0f172a;
}

/* پس‌زمینهٔ شبکهٔ شمسه — بسیار کم‌رنگ تا متن خوانا بماند */
.card-bg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.card-bg rect{fill:url(#orn-mesh)}

/* ── سربرگ ───────────────────────────────────────────────────── */
.card-head{
  position:absolute; top:0; right:0; left:0; height:13mm;
  background:var(--cp);
  color:#fff;
  padding:1.6mm 3mm 0 3mm;
  overflow:hidden;
}
.card-head::after{           /* نوار باریک رنگ دوم، لبهٔ پایین سربرگ */
  content:''; position:absolute; bottom:0; right:0; left:0; height:0.9mm;
  background:var(--ca);
}
.card-school{font-size:3.5mm;font-weight:700;line-height:1.25;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-meta{font-size:2.3mm;line-height:1.3;opacity:.92;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* شمسهٔ کم‌رنگ گوشهٔ سربرگ — امضای بصری کارت */
.card-head-orn{
  position:absolute; top:-4mm; left:-4mm; width:20mm; height:20mm;
  opacity:.16; fill:none; stroke:#fff; stroke-width:1.6;
}
.card-logo{
  position:absolute; top:2mm; left:3mm; width:9mm; height:9mm;
  object-fit:contain; background:#fff; border-radius:1mm; padding:.5mm;
}

/* ── بدنه ────────────────────────────────────────────────────── */
.card-body{
  position:absolute; top:13mm; right:0; left:0; bottom:0;
  padding:2.4mm 3mm 2mm 3mm;
}
.card-photo{
  position:absolute; top:2.4mm; right:3mm;
  width:16mm; height:21mm;
  border:0.3mm solid var(--cp); border-radius:1.5mm;
  object-fit:cover; background:#f1f5f9;
}
.card-photo-ph{                 /* وقتی عکس ندارد */
  position:absolute; top:2.4mm; right:3mm;
  width:16mm; height:21mm;
  border:0.3mm dashed #cbd5e1; border-radius:1.5mm;
  background:#f8fafc;
}
.card-photo-ph svg{width:9mm;height:9mm;margin:6mm 3.5mm;opacity:.35;
  fill:none;stroke:#64748b;stroke-width:1.6}

.card-info{position:absolute;top:2.4mm;right:21mm;left:23mm}
.card-name{font-size:4.1mm;font-weight:700;line-height:1.3;color:#0f172a;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-father{font-size:2.5mm;line-height:1.35;color:#475569;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-rows{margin-top:1.4mm}
.card-row{font-size:2.7mm;line-height:1.55;color:#1e293b;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-row b{color:var(--cp);font-weight:700}
.card-row .lb{color:#64748b;font-weight:400}

/* QR گوشهٔ چپ پایین */
.card-qr-box{position:absolute;bottom:2mm;left:3mm;width:18mm;text-align:center}
.card-qr{width:18mm;height:18mm;display:block}
.card-qr-cap{font-size:1.9mm;line-height:1.4;color:#64748b;margin-top:.4mm}

/* نوار پایین: سال تحصیلی + بته‌جقه */
.card-foot{
  position:absolute; bottom:1.6mm; right:3mm; left:23mm;
  display:block;
}
.card-year{font-size:2.5mm;line-height:1.4;color:#334155}
.card-year b{color:var(--ca);font-weight:700}
.card-boteh{
  position:absolute; bottom:1.2mm; right:19mm; width:7mm; height:7mm;
  opacity:.2; fill:none; stroke:var(--cp); stroke-width:2.2;
}

/* ── طرح‌ها ──────────────────────────────────────────────────── */
/* کلاسیک: طاق ایرانی پشت عکس + قاب گره‌چینی */
.th-classic{border:0.35mm solid var(--cp)}
.th-classic .card-arch{
  position:absolute; top:14mm; right:2.2mm; width:17.6mm; height:23mm;
  opacity:.13; fill:none; stroke:var(--cp); stroke-width:2.4;
}
.th-classic .card-band{
  position:absolute; bottom:0; right:0; left:0; height:2.2mm;
  background:url(#orn-band);
}
.th-ribbon .card-head{height:16mm}
.th-ribbon .card-body{top:16mm}
.th-ribbon .card-photo,.th-ribbon .card-photo-ph{height:19mm}
.th-ribbon{border:0.3mm solid #e2e8f0}
.th-ribbon .card-arch{display:none}
.th-minimal .card-head{background:#fff;color:#0f172a;border-bottom:0.5mm solid var(--cp);height:11mm}
.th-minimal .card-head::after{background:var(--ca);height:0.5mm}
.th-minimal .card-head-orn{stroke:var(--cp);opacity:.12}
.th-minimal .card-body{top:11mm}
.th-minimal{border:0.3mm solid #cbd5e1}
.th-minimal .card-arch{display:none}
.th-minimal .card-bg{display:none}

/* ── پشت کارت ────────────────────────────────────────────────── */
.card-back{
  position:relative;
  width:85.6mm; height:54mm;
  display:inline-block; vertical-align:top;
  margin:0 0 var(--gap,4mm) var(--gap,4mm);
  background:#fff; border:0.3mm solid #e2e8f0; border-radius:3mm;
  overflow:hidden; page-break-inside:avoid; break-inside:avoid;
  padding:3mm 4mm;
}
.card-back-t{font-size:3mm;font-weight:700;color:var(--cp);line-height:1.5;margin-bottom:1.4mm}
.card-back-l{font-size:2.4mm;line-height:1.75;color:#334155}
.card-back-l li{margin-right:3.4mm;list-style:disc}
.card-back-foot{
  position:absolute;bottom:2.4mm;right:4mm;left:4mm;
  border-top:0.25mm solid #e2e8f0;padding-top:1.2mm;
  font-size:2.2mm;line-height:1.45;color:#64748b
}
.card-back-orn{
  position:absolute;bottom:3mm;left:4mm;width:11mm;height:11mm;
  opacity:.14;fill:none;stroke:var(--cp);stroke-width:2;
}
