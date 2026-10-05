# بازگشت به حالت قبل — پیش از بازطراحی فیلترها (v4.179.0)

تاریخ ثبت: ۱۴۰۵/۰۷/۱۳ (۲۰۲۶-۱۰-۰۵)
شاخه: `arena/01a10d10-baci`
کامیتِ حالتِ ثبت‌شده: `4697f82f95a22b0df0c3e7aa296d48c7969795e1`

این سند + بستهٔ `ROLLBACK-V4.179.0-BEFORE-FILTER-REDRAW.zip` **دقیقاً همان حالتی**
را نگه می‌دارند که پیش از این سه تغییر وجود داشت:

1. حذف آیکون خانه (`school-footer-mark`) از فوتر
2. حذف پیوند «بازگشت به محتوا» از فوتر
3. بازطراحی ردیف فیلترهای صفحهٔ مدیریت دانش‌آموزان

اگر نتیجه را نپسندیدید، همین فایل‌ها را برگردانید.

sha256 بستهٔ بازگشت:
`e98aab61612a14cc65e1e8887ef3195afd4c83ca6b134262cf81988018a77fb5`

---

## ۱) حالت فعلی فوتر (`includes/footer.php` — ۳٬۹۱۵ بایت)

ساختار برند فوتر **پیش از** این تغییر:

```html
<div class="school-footer-brand">
  <span class="school-footer-mark"><svg data-ui-icon="school" …></svg></span>
  <strong>نام مدرسه</strong>
</div>
<div class="school-footer-credit">طراحی و توسعه : معاونت فناوری متوسطه اول</div>
<nav class="school-footer-links" aria-label="پیوندهای پایین صفحه">
  <a href="#main-content">بازگشت به محتوا</a>
  <a href="my-sessions.php">نشست‌های من</a>   <!-- فقط برای کاربر واردشده -->
</nav>
```

نکته‌ها:

- `school-footer-mark` همان کادر ۴۲×۴۲ با آیکون SVG مدرسه است
  (`.school-footer-mark` در `assets/css/school-ui.css:65`).
- پیوند «بازگشت به محتوا» به لنگر `id="main-content"` می‌رفت که در
  `includes/header.php` خطوط ۲۲۳ و ۲۶۳ تعریف شده. **کارکردش با دکمهٔ
  «بازگشت به بالا» یکی بود** — کاربر درست می‌گوید: متنش «بازگشت به محتوا»
  است، نه «بازگشت به بالا».
- «نشست‌های من» باید **بماند**؛ در فهرست حذف نبود.
- زیرعنوان و بج نسخه پیش‌تر حذف شده‌اند و در این حالت هم نیستند.

---

## ۲) حالت فعلی فیلترهای مدیریت دانش‌آموزان (`students.php` — ۶۲٬۶۶۱ بایت)

فرم `class="filters-line"` (خط ~۳۹۰) — **ردیف اصلی**:

| # | فیلتر | `name` | |
|---|---|---|---|
| ۱ | جستجو | `q` | متن |
| ۲ | سال تحصیلی | `academic_year` | از `$yearOptions` |
| ۳ | پایه | `grade_level` | از `$gradeOptions` |
| ۴ | **چینش فهرست** | `sort` | ۷ گزینه: name / first / class / grade / nid / gpa / disc |
| ۵ | **ترتیب** | `dir` | asc / desc |
| — | دکمه‌ها | — | «فیلتر» (submit) و «حذف» (لینک به `students.php`) |

`<details class="filter-more">` — **«گزینه‌های بیشتر»**، گرید `grid-cols-5`:

| # | فیلتر | `name` |
|---|---|---|
| ۱ | **کلاس** | `class_name` (از `$classOptions`) |
| ۲ | فیلتر انضباطی | `discipline_filter` (has_records / no_records / recent_30) |
| ۳ | فیلتر تحصیلی | `academic_filter` (excellent / weak / failed) |
| ۴ | درس خاص | `subject_name` (از `$subjectOptions`) |
| ۵ | حداقل / حداکثر معدل | `min_gpa` / `max_gpa` |

شرط بازبودنِ `details`:

```php
($filterClass||$disciplineFilter||$academicFilter||$subjectFilter||$minGpa||$maxGpa)?'open':''
```

### منطق چینش در PHP (خطوط ۳۱۷–۳۷۰) — **دست‌نخورده بماند**

```php
$sortKey = trim($_GET['sort'] ?? '');
if (!in_array($sortKey, ['class','name','first','grade','nid','gpa','disc'], true)) $sortKey = 'name';
$sortDir = (trim($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
```

و هفت شاخهٔ `usort` بر اساس `$sortKey`، سپس
`if ($sortDir === 'desc') $studentsList = array_reverse($studentsList);`

> **مهم:** حذفِ دو dropdown از HTML نباید این منطق را ببرد. سرستون‌های جدول
> با `$stuSortLink()` لینک `?sort=X&dir=Y` می‌سازند (خط ~۴۴۲) و کاربر دقیقاً
> از همان‌جا چینش را عوض می‌کند. پس `sort`/`dir` باید در URL کار کنند.

### کلاس‌های CSS درگیر

| کلاس | کجا تعریف شده | کجا استفاده می‌شود |
|---|---|---|
| `.filters-line` | `school-ui.css:96` | `students.php` **و** `bot-inbox.php` |
| `.filter-primary` | — (بدون قاعدهٔ اختصاصی) | `students.php` **و** `bot-inbox.php` |
| `.filter-more` | `ui-modern.css:305-307`، `school-ui.css:115` | فقط `students.php` |

> `.filters-line` و `.filter-primary` با `bot-inbox.php` **مشترک‌اند**؛ هر CSS
> تازه‌ای باید با یک کلاس صفحه‌ای (مثل `.students-filters`) محدود شود تا
> صفحهٔ صندوق ورودی ربات به هم نریزد.

---

## ۳) روش بازگرداندن

```bash
unzip -o ROLLBACK-V4.179.0-BEFORE-FILTER-REDRAW.zip
python3 scripts/release-v4.179.0.py     # بسته‌های اصلاحی را دوباره بسازید
SITE=<مسیر سایت> PATCH=update-v4.152.0 bash scripts/run-tests.sh
```

یا با git:

```bash
git checkout 4697f82 -- update-v4.152.0/students.php \
                        update-v4.152.0/includes/footer.php \
                        desktop-app-v2/patch/includes-footer.php \
                        update-v4.152.0/assets/css/school-ui.css \
                        update-v4.152.0/assets/js/ui-modern.js
```

---

## ۴) بسته‌های اصلاحیِ همان حالت (اگر نصب‌شده دارید)

| بسته | بایت | sha256 |
|---|---|---|
| `MODIFIED-FILES-V4.179.0.zip` | ۱۸٬۶۵۱ | `544cceae6234a5e0f381d0a70c4e795989554fef52ec88ea9ac54040469edda3` |
| `SchoolDesk-FIX-v2.102.0.zip` | ۱۸٬۶۲۷ | `40bdf298cecf6486688512ebf62733d93cccb1267117e8a60d6ab96d28ff457d` |

هر دو در کامیت `4697f82` هستند و از GitHub قابل دریافت‌اند.

> این اعداد مربوط به **همان زمان** است. بسته‌های v4.179.0 بعداً دو بار
> بازسازی شدند (بازطراحی فیلترها، سپس تنظیم اندازه‌ها) و اکنون بزرگ‌ترند.
> برای بازگشت به حالتِ این سند، همین zip بازگشت کافی است.

---

## ۵) بستهٔ بازگشت دوم — پیش از تنظیم اندازه‌ها

بازطراحی فیلترها (حذف دو dropdown و آوردن «کلاس» به ردیف اصلی) پذیرفته شد،
اما **نسبتِ عرض فیلدها** تنظیم نبود. پیش از آن مرحله هم یک بستهٔ بازگشت
ساخته شد، تا اگر نسبت‌ها را نپسندیدید فقط یک مرحله عقب بروید نه تا اول کار.

**`ROLLBACK-V4.179.0-BEFORE-FILTER-SIZING.zip`** — ۴۷٬۷۳۲ بایت
sha256 `88fe34e7f8ab85e6ca6792e29bcdf67365bdae010d818b8491c1f3f8ea3b6901`

در این حالت، فیلترها این‌طور بودند:

| فیلد | کلاس | `flex` |
|---|---|---|
| جستجو | `filter-primary:first-child` | `2 1 240px` |
| سال تحصیلی / پایه / کلاس | `filter-primary` | `1 1 160px` (هر سه یک‌اندازه) |

- `filter-more` با `flex:1 1 100%` در سطر **جداگانه** بود و دکمه‌ها در سطر بالا.
- گرید «گزینه‌های بیشتر» یک‌اندازه بود: `repeat(auto-fit,minmax(165px,1fr))`.
- کش‌شکن `school-ui.css` برابر `v20261005a` بود.

### بازگرداندن

```bash
unzip -o ROLLBACK-V4.179.0-BEFORE-FILTER-SIZING.zip
cp -r site-update-v4.152.0/. /path/to/site/
```

فقط سه فایل واقعاً فرق می‌کنند: `students.php`، `assets/css/school-ui.css` و
`includes/header.php`.

> ⚠️ اگر این بسته را برگردانید، `tests/test-students-filters.mjs` **۲۴ بررسی
> قرمز** می‌دهد — چون آن تست دقیقاً همین اندازه‌ها و چیدمان را می‌سنجد. این
> عمدی است: نگهبان باید با کد قدیمی قرمز شود.

---

## ۶) بستهٔ بازگشت سوم — پیش از تنظیم ریتم فاصله‌ها

اندازهٔ فیلدها درست شد، اما **ارتفاع و فاصله‌ها** دست‌نخورده مانده بودند.
پیش از آن مرحله هم یک بستهٔ بازگشت ساخته شد.

**`ROLLBACK-V4.179.0-BEFORE-FILTER-SPACING.zip`** — ۴۸٬۵۱۰ بایت
sha256 `49ce7b0894af9af0db2e1d01c9dceccd85e8ecb5b6a3e139bb511caedd4ea4bd`

در این حالت:

| | مقدار |
|---|---|
| ارتفاع ورودی | ≈۴۱ پیکسل (`padding:.65rem .9rem` + `min-height:34px`) |
| ارتفاع دکمه | ≈۳۶ پیکسل (`padding:.6rem 1.25rem`) |
| ارتفاع `summary` | ۴۴ پیکسل (`school-ui.css:115`، داخل `@media screen`) |
| `gap` ردیف | `10px 12px` |
| جعبهٔ `.filter-more` | خط‌چین + `padding:.5rem` + `margin-top:.5rem` |
| `filter-bar` | `align-items:center`، بدون خط جداکننده |
| کارت فیلتر | بدون کلاس اختصاصی، پدینگ ۲۴ پیکسلِ `.card` |
| کش‌شکن | `v20261006a` |

### بازگرداندن

```bash
unzip -o ROLLBACK-V4.179.0-BEFORE-FILTER-SPACING.zip
cp -r site-update-v4.152.0/. /path/to/site/
```

فقط `students.php`، `assets/css/school-ui.css` و `includes/header.php` فرق
می‌کنند.

> ⚠️ با این بسته، `tests/test-students-filters.mjs` **۱۹ بررسی قرمز** می‌دهد
> و پیام تشخیصی‌اش `gap فعلی: gap:10px 12px` است.
