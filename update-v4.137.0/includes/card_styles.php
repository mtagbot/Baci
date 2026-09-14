/* ══════════════════════════════════════════════════════════════════
   کارت ورود دانش‌آموز — v4.137.0
   ابعاد ISO/IEC 7810 ID-1: ۸۵٫۶ × ۵۴ میلی‌متر (کارت بانکی/کارت ملی)

   بازطراحی v4.137.0 — اولویت با «عملکرد»:
     · QR از ۱۸ به ۳۰ تا ۳۶ میلی‌متر بزرگ شد (بسته به طرح). یعنی
       حدود ۴ برابر مساحت قبلی؛ دوربین از فاصلهٔ بیشتر و زاویهٔ بازتر
       می‌خواند.
     · QR روی «هر دو طرف» کارت است، پس جهت قرارگرفتن کارت در هولدر
       اهمیتی ندارد.
     · نام پدر حذف شد (خواستهٔ کارفرما) و همان فضا به QR رسید.
     · شش طرح با فونت‌های ایرانی مختلف.

   این فایل هم در پیش‌نمایش و هم در نمای چاپ include می‌شود تا خروجی
   دو جا از هم جدا نیفتد — درسی که در v4.135.0 گرفتیم.
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
.card-bg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.card-bg rect{fill:url(#orn-mesh)}

/* ── سربرگ ───────────────────────────────────────────────────── */
.card-head{
  position:absolute; top:0; right:0; left:0; height:11mm;
  background:var(--cp); color:#fff;
  padding:1.3mm 3mm 0 3mm; overflow:hidden;
}
.card-head::after{
  content:''; position:absolute; bottom:0; right:0; left:0; height:0.8mm;
  background:var(--ca);
}
.card-school{font-size:3.3mm;font-weight:700;line-height:1.2;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-meta{font-size:2.1mm;line-height:1.25;opacity:.92;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-head-orn{
  position:absolute; top:-3.5mm; left:-3.5mm; width:17mm; height:17mm;
  opacity:.16; fill:none; stroke:#fff; stroke-width:1.6;
}
.card-logo{
  position:absolute; top:1.6mm; left:2.6mm; width:7.6mm; height:7.6mm;
  object-fit:contain; background:#fff; border-radius:1mm; padding:.4mm;
}

/* ── بدنه ────────────────────────────────────────────────────── */
.card-body{
  position:absolute; top:11mm; right:0; left:0; bottom:0;
  padding:2mm 3mm 1.6mm 3mm;
}

/* ── QR بزرگ: قلب کارت ───────────────────────────────────────────
   ۳۰ میلی‌متر در حالت پایه. با احتساب حاشیهٔ سفید (quiet zone) که
   خودِ canvas می‌سازد، ماژول‌های QR به‌اندازهٔ کافی درشت می‌شوند که
   دوربین گوشی از ۲۰ تا ۳۰ سانتی‌متری هم قفل کند. */
.card-qr-box{position:absolute;top:2mm;left:3mm;width:30mm;text-align:center}
.card-qr{width:30mm;height:30mm;display:block;background:#fff}
.card-qr-cap{font-size:1.9mm;line-height:1.3;color:#475569;margin-top:.5mm;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* عکس کوچک‌تر شد تا جا برای QR باز شود */
.card-photo{
  position:absolute; top:2mm; right:3mm;
  width:13mm; height:17mm;
  border:0.3mm solid var(--cp); border-radius:1.2mm;
  object-fit:cover; background:#f1f5f9;
}
.card-photo-ph{
  position:absolute; top:2mm; right:3mm;
  width:13mm; height:17mm;
  border:0.3mm dashed #cbd5e1; border-radius:1.2mm; background:#f8fafc;
}
.card-photo-ph svg{width:7mm;height:7mm;margin:5mm 3mm;opacity:.35;
  fill:none;stroke:#64748b;stroke-width:1.6}

/* ستون اطلاعات — بین عکس و QR */
.card-info{position:absolute;top:2mm;right:17.5mm;left:34.5mm}
.card-name{font-size:4mm;font-weight:700;line-height:1.25;color:#0f172a;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-rows{margin-top:1.2mm}
.card-row{font-size:2.5mm;line-height:1.5;color:#1e293b;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-row b{color:var(--cp);font-weight:700}
.card-row .lb{color:#64748b;font-weight:400}

.card-foot{position:absolute;bottom:1.2mm;right:3mm;left:34.5mm}
.card-year{font-size:2.3mm;line-height:1.35;color:#334155;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-year b{color:var(--ca);font-weight:700}
.card-boteh{
  position:absolute; bottom:1mm; left:35mm; width:6mm; height:6mm;
  opacity:.18; fill:none; stroke:var(--cp); stroke-width:2.2;
}

/* ══ طرح‌ها ═══════════════════════════════════════════════════════
   هر طرح یک فونت ایرانی متفاوت دارد. فونت‌ها در خودِ صفحه با
   @font-face از پوشهٔ uploads بارگذاری می‌شوند و همگی همراه بسته‌اند
   (هیچ CDN و اینترنتی لازم نیست — دسکتاپ آفلاین کار می‌کند).
   ══════════════════════════════════════════════════════════════════ */

/* ۱) کلاسیک — طاق و شمسه · وزیرمتن */
.th-classic{border:0.35mm solid var(--cp);font-family:var(--f-vazir)}
.th-classic .card-arch{
  position:absolute; top:12mm; right:2.3mm; width:14.4mm; height:19mm;
  opacity:.13; fill:none; stroke:var(--cp); stroke-width:2.4;
}
.th-classic .card-band{
  position:absolute; bottom:0; right:0; left:0; height:2mm;
  background:url(#orn-band);
}

/* ۲) نواری — سربرگ بلندتر · ساحل */
.th-ribbon{border:0.3mm solid #e2e8f0;font-family:var(--f-sahel)}
.th-ribbon .card-head{height:13mm}
.th-ribbon .card-body{top:13mm}
.th-ribbon .card-qr-box{top:1.4mm}
.th-ribbon .card-photo,.th-ribbon .card-photo-ph{height:15mm}
.th-ribbon .card-arch{display:none}

/* ۳) مینیمال — سربرگ سفید · یکان */
.th-minimal{border:0.3mm solid #cbd5e1;font-family:var(--f-yekan)}
.th-minimal .card-head{background:#fff;color:#0f172a;
  border-bottom:0.5mm solid var(--cp);height:10mm}
.th-minimal .card-head::after{background:var(--ca);height:0.5mm}
.th-minimal .card-head-orn{stroke:var(--cp);opacity:.12}
.th-minimal .card-body{top:10mm}
.th-minimal .card-bg{display:none}
.th-minimal .card-arch{display:none}

/* ۴) تیتر — سربرگ پررنگ با فونت تیتر، بیشترین کنتراست */
.th-titr{border:0.4mm solid var(--cp);font-family:var(--f-vazir)}
.th-titr .card-head{height:12mm;background:linear-gradient(135deg,var(--cp),var(--ca))}
.th-titr .card-school{font-family:var(--f-titr);font-size:3.6mm;letter-spacing:0}
.th-titr .card-name{font-family:var(--f-titr);font-size:4.2mm}
.th-titr .card-body{top:12mm}
.th-titr .card-arch{display:none}
.th-titr .card-band{
  position:absolute;bottom:0;right:0;left:0;height:1.6mm;background:var(--ca);opacity:.28;
}

/* ۵) کاشی — قاب گره‌چینی دور تا دور، الهام از کاشی‌کاری */
.th-tile{border:0.5mm double var(--cp);font-family:var(--f-sahel)}
.th-tile .card-head{background:var(--cp)}
.th-tile .card-bg rect{fill:url(#orn-mesh);opacity:.85}
.th-tile .card-frame{
  position:absolute;inset:1.2mm;border:0.25mm solid var(--ca);
  border-radius:2mm;opacity:.45;pointer-events:none;
}
.th-tile .card-arch{display:none}

/* ۶) سرو — بته‌جقه و سرو، ملایم و روشن */
.th-sarv{border:0.3mm solid var(--ca);font-family:var(--f-vazir)}
.th-sarv .card-head{background:#fff;color:#0f172a;height:10mm;
  border-bottom:0.4mm solid var(--ca)}
.th-sarv .card-head::after{display:none}
.th-sarv .card-head-orn{stroke:var(--ca);opacity:.18}
.th-sarv .card-body{top:10mm}
.th-sarv .card-boteh{opacity:.3;stroke:var(--ca);width:8mm;height:8mm}
.th-sarv .card-arch{display:none}
.th-sarv .card-bg rect{opacity:.5}

/* ══ پشت کارت — حالا خودش هم QR دارد ═════════════════════════════
   خواستهٔ کارفرما: «هر دو طرف کارت تگ باشد». پس پشت کارت دیگر فقط
   متن مقررات نیست؛ یک QR بزرگ‌تر (۳۶ میلی‌متر) وسط آن است تا اگر
   کارت برعکس در هولدر باشد، باز هم اسکن شود. */
.card-back{
  position:relative;
  width:85.6mm; height:54mm;
  display:inline-block; vertical-align:top;
  margin:0 0 var(--gap,4mm) var(--gap,4mm);
  background:#fff; border:0.3mm solid #e2e8f0; border-radius:3mm;
  overflow:hidden; page-break-inside:avoid; break-inside:avoid;
  font-size:0; line-height:0;
}
.card-back-in{position:absolute;inset:0;padding:2.4mm 3mm}
.card-back-qr-box{
  position:absolute;top:50%;left:3.5mm;width:36mm;margin-top:-20mm;text-align:center;
}
.card-back-qr{width:36mm;height:36mm;display:block;background:#fff}
.card-back-qr-cap{font-size:2mm;line-height:1.35;color:#475569;margin-top:.6mm}
.card-back-side{position:absolute;top:2.6mm;right:3mm;left:41mm}
.card-back-t{font-size:2.7mm;font-weight:700;color:var(--cp);line-height:1.4;margin-bottom:1mm}
.card-back-name{font-size:3.1mm;font-weight:700;color:#0f172a;line-height:1.3;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-back-sub{font-size:2.3mm;line-height:1.45;color:#475569;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-back-l{font-size:2.05mm;line-height:1.5;color:#475569;margin-top:1.2mm}
.card-back-l li{margin-right:3mm;list-style:disc}
.card-back-foot{
  position:absolute;bottom:2mm;right:3mm;left:41mm;
  border-top:0.25mm solid #e2e8f0;padding-top:1mm;
  font-size:2mm;line-height:1.35;color:#64748b
}
.card-back-orn{
  position:absolute;top:2mm;left:41.5mm;width:8mm;height:8mm;
  opacity:.12;fill:none;stroke:var(--cp);stroke-width:2;
}
