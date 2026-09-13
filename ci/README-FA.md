# فعال‌سازی اجرای خودکار تست (CI)

فایل `ci/tests.yml` آمادهٔ استفاده است، ولی **هنوز فعال نیست**.

## چرا اینجاست و نه در `.github/workflows/`

GitHub اجازه نمی‌دهد یک GitHub App فایل داخل `.github/workflows/` را
بسازد یا تغییر دهد، مگر اینکه دسترسی `workflows` داشته باشد. push با
این خطا رد شد:

```
refusing to allow a GitHub App to create or update workflow
`.github/workflows/tests.yml` without `workflows` permission
```

پس فایل اینجا گذاشته شد تا محتوایش از دست نرود. فعال‌کردنش یک دستور
است و باید **توسط خود شما** انجام شود.

## فعال‌سازی (یک بار، حدود ۳۰ ثانیه)

روی کامپیوتر خودتان، در همین مخزن:

```bash
git checkout arena/01a097e6-baci
git pull
mkdir -p .github/workflows
git mv ci/tests.yml .github/workflows/tests.yml
git commit -m "ci: activate the test workflow"
git push
```

از همان لحظه، روی هر push و هر Pull Request همهٔ سوئیت‌ها اجرا می‌شوند
و نتیجه‌شان کنار کامیت دیده می‌شود.

روش جایگزین (بدون دستور): در وب‌سایت GitHub → Add file → Create new
file → نام را `.github/workflows/tests.yml` بگذارید و محتوای
`ci/tests.yml` را در آن کپی کنید.

## این workflow چه می‌کند

| مرحله | کار |
|---|---|
| checkout | دریافت کد |
| setup-node | نصب Node 22 |
| cache | کش `tests/node_modules` (اجرای بعدی سریع‌تر) |
| npm install | نصب `@php-wasm/node` |
| `rm -rf .arena` | **مهم** — تا از بستهٔ منتشرشده تست گرفته شود نه از پوشهٔ محلی |
| run-tests.sh | اجرای هر ۱۱ سوئیت |

## چرا این مرحله «`rm -rf .arena`» مهم است

`resolveSite()` اول سراغ `.arena/current` می‌رود و فقط اگر نبود،
جدیدترین `SchoolDeskPro-v*-win64.zip` را باز می‌کند. اگر `.arena` پاک
نشود، ممکن است تست‌ها بی‌صدا روی بستهٔ قدیمی اجرا شوند.

همین دسته اشتباه تا امروز **چهار بار** تکرار شده است (موارد ۱، ۱۱، ۱۴
و ۲۳ در `docs/CODE-MAP-FA.md`): تست سبز بود ولی چیزی را که فکر
می‌کردیم نمی‌سنجید. CI دقیقاً برای گرفتن همین ساخته شده، چون همیشه از
صفر و روی همان چیزی اجرا می‌شود که به مدرسه تحویل می‌رود.
