/* ══════════════════════════════════════════════════════════════════
   کارت ورود دانش‌آموز — v4.138.0
   ابعاد ISO/IEC 7810 ID-1: ۸۵٫۶ × ۵۴ میلی‌متر

   بازطراحی v4.138.0:
     · QR باز هم بزرگ‌تر: ۴۰mm روی کارت، ۴۴mm پشت کارت.
       سهم QR از سطح کارت: ۳۵٪ روی، ۴۲٪ پشت. نسبت به نسخهٔ اول
       (۱۸mm) نزدیک به ۵ برابر مساحت.
     · تنوع واقعی تصویرسازی: هر طرح تصویر شاخص، بافت پس‌زمینه و
       چیدمان خودش را دارد — نه فقط فونت و رنگ متفاوت.

   چیدمان نو: QR ستون راست را کامل می‌گیرد، اطلاعات در ستون چپ
   می‌نشیند. عکس دانش‌آموز کوچک و کنار اطلاعات است. این تنها آرایشی
   بود که ۴۰mm را بدون له‌کردن متن جا می‌داد.
   ══════════════════════════════════════════════════════════════════ */
.card-id{
  position:relative;
  width:85.6mm; height:54mm;
  display:inline-block; vertical-align:top;
  /* v4.143.0 — فاصله فقط *بین* کارت‌ها، نه بعد از آخرین کارت ردیف.
     پیش‌تر هر کارت margin-left داشت، پس عرض اشغالی هر کارت
     (کارت + gap) بود و ردیف یک ستون کمتر از محاسبهٔ PHP جا می‌داد؛
     کارت آخر به ردیف بعد می‌افتاد و صفحه به‌هم می‌ریخت.
     حالا margin سمت راست است (چیدمان RTL) و اولین کارتِ هر ردیف
     آن را ندارد — این دقیقاً همان فرمولی است که PHP حساب می‌کند. */
  margin:0 var(--gap,4mm) var(--gap,4mm) 0;
  background:#fff;
  border-radius:3mm;
  overflow:hidden;
  page-break-inside:avoid; break-inside:avoid;
  font-size:0; line-height:0;
  color:#0f172a;
}
/* v4.143.0 — ظرف کارت‌ها: عرض را به‌اندازهٔ یک gap بیشتر می‌گیریم
   تا margin سمت راستِ آخرین کارتِ هر ردیف بیرون از فضای قابل چاپ
   بیفتد. نتیجه: دقیقاً همان تعداد ستونی که PHP حساب کرده جا می‌شود،
   بدون نیاز به :nth-child که با تعداد ستون متغیر کار نمی‌کند. */
.sheet{margin-right:calc(var(--gap,4mm) * -1)}

.card-bg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.card-bg rect{fill:url(#orn-mesh)}

/* ── سربرگ باریک ─────────────────────────────────────────────── */
.card-head{
  position:absolute; top:0; right:0; left:0; height:9mm;
  background:var(--cp); color:#fff;
  padding:1mm 2.6mm 0 2.6mm; overflow:hidden;
}
.card-head::after{
  content:''; position:absolute; bottom:0; right:0; left:0; height:0.7mm;
  background:var(--ca);
}
.card-school{font-size:2.9mm;font-weight:700;line-height:1.15;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-meta{font-size:1.85mm;line-height:1.2;opacity:.9;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-head-orn{
  position:absolute; top:-3mm; left:-3mm; width:14mm; height:14mm;
  opacity:.18; fill:none; stroke:#fff; stroke-width:1.7;
}
.card-logo{
  position:absolute; top:1.2mm; left:2.2mm; width:6.4mm; height:6.4mm;
  object-fit:contain; background:#fff; border-radius:.8mm; padding:.3mm;
}

.card-body{
  position:absolute; top:9mm; right:0; left:0; bottom:0;
  padding:1.6mm 2.6mm 1.4mm 2.6mm;
}

/* ── QR: ستون راست، ۴۰ میلی‌متر ─────────────────────────────────
   بزرگ‌ترین عنصر کارت. کپشن زیرش عمداً کوتاه است تا ارتفاع نخورد. */
.card-qr-box{position:absolute;top:1.4mm;right:2.6mm;width:40mm;text-align:center}
.card-qr{width:40mm;height:40mm;display:block;background:#fff}
.card-qr-cap{font-size:1.8mm;line-height:1.25;color:#475569;margin-top:.3mm;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── ستون اطلاعات (چپ) ──────────────────────────────────────── */
.card-info{position:absolute;top:16.6mm;left:2.6mm;width:37mm}
.card-name{font-size:3.7mm;font-weight:700;line-height:1.2;color:#0f172a;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-rows{margin-top:1mm}
.card-row{font-size:2.35mm;line-height:1.45;color:#1e293b;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-row b{color:var(--cp);font-weight:700}
.card-row .lb{color:#64748b;font-weight:400}

/* v4.140.0 — جای عکس و اطلاعات عوض شد (خواستهٔ کارفرما):
   عکس حالا بالای ستون چپ است و اطلاعات زیر آن. پیش‌تر اطلاعات بالا
   و عکس پایین بود. */
.card-photo{
  position:absolute; top:1.4mm; left:2.6mm;
  width:11mm; height:14mm;
  border:0.3mm solid var(--cp); border-radius:1mm;
  object-fit:cover; background:#f1f5f9;
}
.card-photo-ph{
  position:absolute; top:1.4mm; left:2.6mm;
  width:11mm; height:14mm;
  border:0.3mm dashed #cbd5e1; border-radius:1mm; background:#f8fafc;
}
.card-photo-ph svg{width:6mm;height:6mm;margin:4mm 2.5mm;opacity:.35;
  fill:none;stroke:#64748b;stroke-width:1.6}

/* تصویر شاخص هر طرح — کنار عکس، فضای خالی ستون چپ را پر می‌کند */
.card-hero{
  position:absolute; top:1.4mm; left:14.5mm;
  width:22mm; height:14mm;
  opacity:.17; fill:none; stroke:var(--cp); stroke-width:2.2;
  stroke-linecap:round; stroke-linejoin:round;
}

.card-foot{position:absolute;bottom:1.2mm;left:2.6mm;width:37mm}
.card-year{font-size:2.15mm;line-height:1.3;color:#334155;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-year b{color:var(--ca);font-weight:700}

/* ══ شش طرح — هر کدام تصویر، بافت و شخصیت خودش ═══════════════════ */

/* ۱) کلاسیک — محراب و شمسه · وزیرمتن · بافت شمسه */
.th-classic{border:0.35mm solid var(--cp);font-family:var(--f-vazir)}
.th-classic .card-bg rect{fill:url(#orn-mesh)}
.th-classic .card-band{
  position:absolute; bottom:0; right:0; left:0; height:1.8mm;
  background:url(#orn-band);
}

/* ۲) نواری — گنبد · ساحل · بافت موج */
.th-ribbon{border:0.3mm solid #e2e8f0;font-family:var(--f-sahel)}
.th-ribbon .card-head{height:11mm;background:linear-gradient(90deg,var(--cp),var(--ca))}
.th-ribbon .card-body{top:11mm}
.th-ribbon .card-qr-box{top:1mm}
.th-ribbon .card-bg rect{fill:url(#orn-wave)}
.th-ribbon .card-hero{stroke:var(--ca);opacity:.2}

/* ۳) مینیمال — فروهر · یکان · بدون بافت */
.th-minimal{border:0.3mm solid #cbd5e1;font-family:var(--f-yekan)}
.th-minimal .card-head{background:#fff;color:#0f172a;
  border-bottom:0.45mm solid var(--cp);height:8.5mm}
.th-minimal .card-head::after{background:var(--ca);height:0.45mm}
.th-minimal .card-head-orn{stroke:var(--cp);opacity:.14}
.th-minimal .card-body{top:8.5mm}
.th-minimal .card-bg{display:none}
.th-minimal .card-hero{opacity:.12}

/* ۴) تیتر — ستون تخت‌جمشید · بی‌تیتر · بافت آجر */
.th-titr{border:0.4mm solid var(--cp);font-family:var(--f-vazir)}
.th-titr .card-head{height:10mm;background:linear-gradient(135deg,var(--cp),var(--ca))}
.th-titr .card-school{font-family:var(--f-titr);font-size:3.2mm}
.th-titr .card-name{font-family:var(--f-titr);font-size:3.9mm}
.th-titr .card-body{top:10mm}
.th-titr .card-bg rect{fill:url(#orn-brick)}
.th-titr .card-band{
  position:absolute;bottom:0;right:0;left:0;height:1.4mm;background:var(--ca);opacity:.3;
}

/* ۵) کاشی — ستارهٔ دوازده‌پر · ساحل · بافت شش‌ضلعی + قاب دوتایی */
.th-tile{border:0.5mm double var(--cp);font-family:var(--f-sahel)}
.th-tile .card-bg rect{fill:url(#orn-hex)}
.th-tile .card-frame{
  position:absolute;inset:1.1mm;border:0.25mm solid var(--ca);
  border-radius:2mm;opacity:.5;pointer-events:none;
}
.th-tile .card-hero{stroke:var(--ca);opacity:.22}

/* ۶) سرو — سرو ایرانی · وزیرمتن · بافت قالی */
.th-sarv{border:0.3mm solid var(--ca);font-family:var(--f-vazir)}
.th-sarv .card-head{background:#fff;color:#0f172a;height:8.5mm;
  border-bottom:0.4mm solid var(--ca)}
.th-sarv .card-head::after{display:none}
.th-sarv .card-head-orn{stroke:var(--ca);opacity:.2}
.th-sarv .card-body{top:8.5mm}
.th-sarv .card-bg rect{fill:url(#orn-rug)}
.th-sarv .card-hero{stroke:var(--ca);opacity:.25}

/* ══ پشت کارت — QR ۴۴ میلی‌متری ═══════════════════════════════════ */
.card-back{
  position:relative;
  width:85.6mm; height:54mm;
  display:inline-block; vertical-align:top;
  margin:0 var(--gap,4mm) var(--gap,4mm) 0;
  background:#fff; border:0.3mm solid #e2e8f0; border-radius:3mm;
  overflow:hidden; page-break-inside:avoid; break-inside:avoid;
  font-size:0; line-height:0;
}
.card-back-in{position:absolute;inset:0;padding:2mm 2.6mm}
.card-back-qr-box{
  position:absolute;top:50%;right:2.6mm;width:44mm;margin-top:-24mm;text-align:center;
}
.card-back-qr{width:44mm;height:44mm;display:block;background:#fff}
.card-back-qr-cap{font-size:1.9mm;line-height:1.3;color:#475569;margin-top:.4mm}
.card-back-side{position:absolute;top:2.4mm;left:2.6mm;width:34mm}
.card-back-name{font-size:2.9mm;font-weight:700;color:#0f172a;line-height:1.25;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-back-sub{font-size:2.15mm;line-height:1.35;color:#475569;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-back-l{font-size:1.9mm;line-height:1.4;color:#475569;margin-top:1mm}
.card-back-l li{margin-right:2.6mm;list-style:disc}
.card-back-foot{
  position:absolute;bottom:1.8mm;left:2.6mm;width:34mm;
  border-top:0.25mm solid #e2e8f0;padding-top:.8mm;
  font-size:1.85mm;line-height:1.3;color:#64748b
}
.card-back-orn{
  position:absolute;bottom:8mm;left:3mm;width:14mm;height:14mm;
  opacity:.13;fill:none;stroke:var(--cp);stroke-width:2;
}

/* ══════════════════════════════════════════════════════════════════
   v4.139.0 — حالت «تگ‌محور» (QR-first)
   خواستهٔ کارفرما: «۸۰٪ کارت را QR بگیرد، ۲۰٪ بقیه.»

   یک واقعیت هندسی که باید صریح گفته شود: QR مربع است و ارتفاع کارت
   ویزیت استاندارد فقط ۵۴ میلی‌متر است. مربعی که ۸۰٪ مساحت
   ۸۵٫۶×۵۴ را بگیرد باید ۶۰٫۸ میلی‌متر ضلع داشته باشد — یعنی بلندتر
   از خودِ کارت. حتی QR بی‌حاشیه و تمام‌ارتفاع (۵۴mm) فقط ۶۳٪ می‌شود.

   پس دو مسیر داریم و هر دو ساخته شد:

   الف) شکل مربع ۵۴×۵۴ (پیش‌فرضِ حالت تگ‌محور)
        QR = ۴۸mm  →  **۷۹٪ مساحت کارت**  ← دقیقاً همان چیزی که خواستید
        بقیه یک نوار باریک پایین برای نام و کلاس.

   ب) همان کارت مستطیلی ۸۵٫۶×۵۴
        QR = ۵۰mm  →  ۵۴٪ مساحت کل، ولی **۹۳٪ ارتفاع کارت** و
        بزرگ‌ترین مربع ممکن. اطلاعات در نوار باریک کنار آن.

   در هر دو حالت QR بزرگ‌ترین مربعی است که فیزیک کارت اجازه می‌دهد.
   ══════════════════════════════════════════════════════════════════ */

/* ── الف) کارت مربع تگ‌محور ───────────────────────────────────────
   v4.140.0 — بازنویسی کامل.

   دو ایراد نسخهٔ قبل که با محاسبه پیدا شد:
     ۱) سربرگ ۶٫۴ + فاصله ۰٫۹ + QR ۴۸ = ۵۵٫۳mm روی کارت ۵۴mm،
        یعنی ۱٫۳mm سرریز. نوار اطلاعاتِ پایین عملاً زیر QR می‌رفت —
        همان چیزی که کارفرما دید. تستِ من هم شل بود و نگرفت
        (عدد ۶ را حدسی گذاشته بودم به‌جای ارتفاع واقعی سربرگ).
     ۲) اطلاعات جدا از سربرگ بود و فضای اضافی می‌خورد.

   طرح نو: یک نوار عنوان تک‌خطی «مدرسه — نام دانش‌آموز»، و بقیهٔ
   کارت تماماً QR. کلاس و پایه نمایش داده نمی‌شوند (خواستهٔ کارفرما).

   بودجهٔ ارتفاع، دقیق:  ۴٫۴ + ۰٫۳ + ۴۹٫۰ + ۰٫۳ = ۵۴٫۰mm
   سهم QR: ۸۲٪ مساحت کارت — از هدف ۸۰٪ هم بالاتر.
   ─────────────────────────────────────────────────────────────── */
.card-id.sq{width:54mm;height:54mm;border-radius:2mm}
.card-id.sq .card-head{
  height:4.4mm;padding:0 1.4mm;
  display:-webkit-box;display:-ms-flexbox;display:flex;
  -webkit-box-align:center;-ms-flex-align:center;align-items:center;
}
.card-id.sq .card-head::after{display:none}   /* نوار رنگی حذف: ۰٫۷mm صرفه‌جویی */
.card-id.sq .card-school{
  font-size:2.5mm;line-height:1;width:100%;text-align:center;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.card-id.sq .card-meta,
.card-id.sq .card-head-orn,
.card-id.sq .card-logo{display:none}          /* هر پیکسل برای QR */
.card-id.sq .card-body{top:4.4mm;padding:0}
.card-id.sq .card-qr-box{
  top:0.3mm;right:50%;margin-right:-24.5mm;width:49mm;
}
.card-id.sq .card-qr{width:49mm;height:49mm}
.card-id.sq .card-qr-cap{display:none}
.card-id.sq .card-info,
.card-id.sq .card-rows,
.card-id.sq .card-photo,
.card-id.sq .card-photo-ph,
.card-id.sq .card-hero,
.card-id.sq .card-foot,
.card-id.sq .card-band,
.card-id.sq .card-bg{display:none}            /* همه‌چیز در نوار عنوان آمد */

/* ── ب) کارت مستطیلی، حداکثر QR ممکن ──────────────────────────── */
/* v4.140.0: سربرگ کوتاه‌تر شد. پیش‌تر ۶٫۴ + ۰٫۸ + QR ۵۰ = ۵۷٫۲mm
   روی کارت ۵۴mm بود — یعنی ۳٫۲mm سرریز که QR را از لبهٔ پایین بیرون
   می‌زد. تست قدیمی چون ارتفاع سربرگ را نمی‌خواند، نگرفته بود. */
.card-id.qrmax .card-head{height:4.4mm;padding:0 1.8mm;
  display:-webkit-box;display:-ms-flexbox;display:flex;
  -webkit-box-align:center;-ms-flex-align:center;align-items:center}
.card-id.qrmax .card-head::after{display:none}
.card-id.qrmax .card-school{font-size:2.4mm;line-height:1;max-width:60%}
.card-id.qrmax .card-meta{display:none}
.card-id.qrmax .card-head-orn{display:none}
.card-id.qrmax .card-logo{width:3.6mm;height:3.6mm;top:.4mm;left:1.4mm}
.card-id.qrmax .card-body{top:4.4mm;padding:0}
.card-id.qrmax .card-qr-box{top:0.3mm;right:1.4mm;width:49mm}
.card-id.qrmax .card-qr{width:49mm;height:49mm}
.card-id.qrmax .card-qr-cap{display:none}
.card-id.qrmax .card-info{top:16.5mm;left:1.8mm;width:32mm}
.card-id.qrmax .card-name{font-size:3.4mm}
.card-id.qrmax .card-row{font-size:2.3mm;line-height:1.4}
/* v4.140.0: عکس بالا، اطلاعات پایین — مثل حالت کامل */
.card-id.qrmax .card-photo,
.card-id.qrmax .card-photo-ph{top:0.8mm;left:1.8mm;width:11mm;height:14mm}
.card-id.qrmax .card-hero{top:1.2mm;left:13.4mm;width:18mm;height:13mm}
.card-id.qrmax .card-foot{bottom:.6mm;left:1.8mm;width:31mm}
.card-id.qrmax .card-year{font-size:2mm}

/* ── پشت کارت در حالت تگ‌محور: QR تمام‌صفحه ───────────────────── */
.card-back.sq{width:54mm;height:54mm}
.card-back.sq .card-back-in{padding:1.4mm}
.card-back.sq .card-back-qr-box{
  top:50%;right:50%;margin-right:-25mm;margin-top:-25mm;width:50mm;
}
.card-back.sq .card-back-qr{width:50mm;height:50mm}
.card-back.sq .card-back-qr-cap,
.card-back.sq .card-back-side,
.card-back.sq .card-back-foot,
.card-back.sq .card-back-orn{display:none}

.card-back.qrmax .card-back-in{padding:1.6mm}
.card-back.qrmax .card-back-qr-box{
  top:50%;right:1.8mm;margin-top:-25.5mm;width:51mm;
}
.card-back.qrmax .card-back-qr{width:51mm;height:51mm}
.card-back.qrmax .card-back-qr-cap{display:none}
.card-back.qrmax .card-back-side{top:2mm;left:1.8mm;width:29mm}
.card-back.qrmax .card-back-foot{bottom:1.6mm;left:1.8mm;width:29mm}
.card-back.qrmax .card-back-orn{display:none}
