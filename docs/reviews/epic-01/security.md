# Review Report - Security - Epic 01 (Public marketing & legal site)

## Reviewed scope

- **Epic / change:** Epic 01 (Public marketing & legal site), current working tree
- **Requirements/rules in scope:** SEC-INPUT (escaping), SEC-SECRETS, SEC-DEPS, SEC-HEADERS, QG-SECRETS, QG-SAST, QG-DEPS-VULN. SEC-TENANT/SEC-AUTHZ/SEC-AUTH/SEC-TOKEN/SEC-SESSION/SEC-RATE/SEC-UPLOAD are n/a: this epic adds six anonymous, read-only static pages and no tenant data, accounts, tokens, forms, or uploads

## Files reviewed

- `routes/web.php:7-12` - public routes: GET-only `Route::view()`, no state-changing endpoints added
- `resources/views/components/layouts/public.blade.php` - output escaping, external link hygiene (`rel="noopener"` on GitHub link, line 70)
- `resources/views/marketing/*.blade.php` (6 files) - checked for `{!! !!}`, inline `<script>`, user input echoes; none exist, all output is `{{ }}` over literals/config
- `app/Http/Middleware/SetSecurityHeaders.php` - global headers (nosniff, Referrer-Policy, CSP with `frame-ancestors 'none'`) apply to the new pages via the global stack (Epic 00)
- `config/app.php:28` - repo URL from env, no secret material
- `resources/views/marketing/imprint.blade.php:17` - exposes `config('mail.from.address')` as operator contact (intentional, public contact data)
- `.gitleaks.toml`, `.env.example` - no new secrets introduced; `.env` git-ignored

## Flows reviewed

- Anonymous GET on all six public routes - no auth, no CSRF surface (no forms), no query parameters consumed, no user-controlled data rendered; response carries the Epic 00 security headers (SecurityHeadersTest remains green in the full run).
- Auth boundary - public routes carry no `auth` middleware (asserted by test) and, conversely, leak no authenticated data: views render only static copy and config values.
- External links - GitHub links use `rel="noopener"`; no `target="_blank"` is used, so no reverse-tabnabbing surface.

## Tests reviewed

- `tests/Feature/Public/PublicPagesTest.php::no auth-only routes leak into the public page set` - middleware boundary on public routes
- `tests/Feature/Ops/SecurityHeadersTest.php` (4 tests, Epic 00 suite) - SEC-HEADERS + cookie flags still green with the new pages in place (part of fresh 95/95 run)
- `tests/Browser/PublicSmokeTest.php` - no JS/console errors on any page, confirming the CSP does not break the public pages and no stray scripts execute

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make secrets` | pass | gitleaks: no leaks found (fresh run for this review) |
| `make audit` | pass | composer audit: 0 advisories; npm audit: 0 vulnerabilities (fresh run) |
| `make sast` (build log + artifact) | pass | `reports/semgrep.json`: 0 findings (verified artifact contents) |
| `make osv` (build log) | pass | osv-scanner clean |
| `php artisan test --compact` | pass | 95/95 incl. SecurityHeadersTest (fresh run) |
| `grep -rn "{!!\|<script" resources/views/marketing` | clean | no raw echoes, no inline scripts (fresh run) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation (SEC-TENANT) | n/a | No tenant-owned models or tenant data on public pages |
| 2 | Authorization (SEC-AUTHZ) | ✅ | Nothing state-changing added; intentionally public read-only routes; no privileged data rendered (views use only literals + `config()`) |
| 3 | Authentication (SEC-AUTH) | n/a | No auth flows touched; `login`/`register` links point at existing Fortify routes |
| 4 | Input & injection (SEC-INPUT) | ✅ | No user input consumed; all output Blade-escaped `{{ }}`; zero `{!! !!}` (grep); no SQL anywhere in the epic |
| 5 | Customer tokens (SEC-TOKEN) | n/a | Tokens arrive in Epic 08 |
| 6 | Secrets (SEC-SECRETS) | ✅ | gitleaks clean (fresh); repo URL and contact email are public-by-design config, not secrets; `.env` ignored |
| 7 | Dependencies (SEC-DEPS) | ✅ | composer/npm audit 0 (fresh), osv clean (build log), SBOM `reports/sbom.cdx.json` present |
| 8 | SAST | ✅ | Semgrep 0 findings (`reports/semgrep.json` verified); no `nosemgrep` markers in the epic's files |
| 9 | Headers & transport (SEC-HEADERS) | ✅ | Global `SetSecurityHeaders` middleware covers the new routes; nosniff/Referrer-Policy/CSP incl. `frame-ancestors 'none'` asserted by SecurityHeadersTest; no inline scripts added that would force CSP loosening |
| 10 | Sessions & CSRF (SEC-SESSION) | n/a | No mutations or login flows in scope; existing Epic 00/starter behavior unchanged |
| 11 | Rate limiting (SEC-RATE) | n/a | No login/reset/booking endpoints in this epic |
| 12 | Logging & errors (SEC-LOG) | ✅ | Static pages log nothing sensitive; correlation-ID middleware (Epic 00) applies; no PII rendered beyond the intentional operator contact (imprint) |
| 13 | Uploads (SEC-UPLOAD) | n/a | No uploads in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | SEC-LOG/privacy hygiene | `marketing/imprint.blade.php:17` | Operator contact is `config('mail.from.address')`; if a deployment leaves the default (`hello@example.com`-style) value, the imprint shows a dead contact address | Document in deployment notes that `MAIL_FROM_ADDRESS` must be a monitored mailbox; verify on the demo deployment in Epic 10 |

## Required fixes (blocking)

- None. No Critical/High findings.

## Final decision

**PASS**

- Rationale: the epic's attack surface is six anonymous, read-only, fully escaped static pages behind the existing global security headers; all applicable security gates (secrets, SAST, dependency audits) were re-run clean for this review and no SEC-* rule in scope is violated.
- Blocking findings remaining: 0
