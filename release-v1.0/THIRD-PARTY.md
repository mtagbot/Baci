# Bundled dependencies

- Application reference: `upstream-reference/`, followed by numerically ordered `update-v4.*` through 4.152.0. Desktop base: `SchoolDeskPro-v2.55.0-win64.zip`, with platform-safe cumulative overlays through desktop 2.83.0.
- Windows PHP 8.1.34 NTS x64 and its existing extension DLLs/CA bundle: unchanged from the full desktop baseline. Redistributable notices for PHP, OpenSSL 1.1, libssh2, nghttp2 and SQLite are included under desktop `licenses/`. PHP 8.1 is end-of-life; use this embedded runtime only for trusted local desktop use, not a public server. The original VC runtime DLLs are retained; the Microsoft VC++ Redistributable installer and an installed Edge/Chrome browser are not included. Microsoft DLLs remain proprietary Microsoft components.
- TCPDF **6.11.4**, commit `9fbcd75b9f2a727605d7c0a84a4be18f4026de7d`: https://github.com/tecnickcom/TCPDF/tree/6.11.4 — LGPL-3.0-or-later. Necessary PHP sources and DejaVu Sans/core fonts included; license at `vendor/tcpdf/LICENSE.TXT` (under `www/` on desktop). Individual source blobs are pinned in `release-v1.0/tcpdf-files.json`. TCPDF 7 requires a newer runtime and additional dependencies; it is intentionally not substituted without compatibility testing.
- Chart.js **4.5.1** UMD, https://www.npmjs.com/package/chart.js — MIT. License at `assets/vendor/Chart.js-LICENSE.md`. Local bundle replaces the previously missing local Chart.js file; no CDN is needed for charts.
- jsQR and qrcode-generator: existing project distributions in `assets/js`, copyright/license notices retained in their sources.
- Existing user/project supplied Persian fonts under `uploads/`: retained from the full desktop distribution. No new external font downloads or redistribution-license claims.

This is a full application distribution, not a complete operating system. Optional SMS/bot accounts, camera/browser support, Python-based PDF analysis and platform prerequisites still need their own configuration.
