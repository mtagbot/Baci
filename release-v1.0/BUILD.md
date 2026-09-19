# Rebuilding Release_V1.0

Run from the repository root on the session branch. Never modify/rebuild the historical archives.

```sh
python3 -m pip install --target .cache/release-v1/compiler ziglang==0.14.1
npm ci --prefix tests
python3 scripts/build-full-release.py --stage-only
# Run installer, packaging and regression tests before the final archive build.
python3 scripts/build-full-release.py
python3 tests/test-full-release-packaging.py
```

The builder reads the original website reference, every numeric site overlay, the genuine full desktop 2.55 archive, current launcher source, and the isolated `release-v1.0` integration layer. On top of the numeric overlays it also applies the published corrective packages listed in `SITE_CORRECTIVES` / `DESKTOP_CORRECTIVES` (immutable ZIPs, installation order), so a fresh install never needs the corrective chain afterwards. Those packages are inputs too: never edit or repackage a historical corrective ZIP, and never move a slug in the order without re-reading its `CORRECTIONS-*.md` prerequisites.

Desktop packages were published against both web-root names — `www/` before the reports migration and `reports/` after it — and the builder maps both onto `www/`. The rebuilt launcher then performs its one-time, locked migration to `reports/` using the staged `reports-layout-update/router.php` that ships inside the bundle. The launcher binary always comes from `desktop-app-v2/launcher/launcher.c`; the `SchoolDeskPro.exe` inside corrective ZIPs is never copied. It builds the PE32+ Windows launcher with Zig (set `ZIG` to override), obtains hash-pinned TCPDF source blobs through `gh api`, and installs pinned Chart.js 4.5.1 through npm. These downloaded test/build dependencies remain in ignored `.cache`, not in the repository as extracted trees. Licenses travel inside both ZIPs.

Archives have fixed entry timestamps, sorted entries and per-file SHA256 manifests. The manifest records the build source commit, so rebuilding from a different commit deliberately changes the manifest/archive hash. It never copies a runtime database/configuration, browser profile, session, token or log. Only structural SQL statements and complete trigger bodies survive seed removal.

First-install source is shared across platforms; distribution metadata selects MySQL for site and SQLite for desktop. Legacy endpoint key parsing is replaced only in the new release payload. Historical incremental patches keep their existing upgrade semantics.

Integration tests use PHP 8.3 WebAssembly. The optional MySQL path uses a real disposable MySQL server via `@php-wasm/node` networking; it must never point at production. Set `RELEASE_TEST_MYSQL=1` and provide the temporary test password through an ignored file as described in the test source. Native Windows execution/physical camera/printer tests are not provided by this Linux environment.
