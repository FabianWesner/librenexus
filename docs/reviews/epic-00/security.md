# Review Report - Security - Epic 00 (Foundations & quality harness)

## Reviewed scope

- **Epic / change:** Epic 00 (Foundations & quality harness)
- **Requirements/rules in scope:** SEC-HEADERS-1/2/3, SEC-LOG-1/2/3, SEC-SECRETS, SEC-DEPS, QG-SECRETS, QG-SAST, QG-DEPS-VULN; SEC-TENANT/SEC-AUTHZ/SEC-AUTH/SEC-TOKEN/SEC-RATE land in later epics

## Files reviewed

- `app/Http/Middleware/SetSecurityHeaders.php` - SEC-HEADERS-1 header set and CSP
- `app/Http/Middleware/AddCorrelationId.php` - inbound header trust boundary, log-injection surface
- `bootstrap/app.php` - global registration so headers cover every response (lines 18-21)
- `config/session.php` - cookie flags: `secure` env-driven (line 172), `http_only` default true (line 185), `same_site` lax (line 202)
- `app/Http/Controllers/HealthController.php` - information exposure of the public endpoint
- `config/logging.php`, `config/services.php`, `.env.example` - secrets handling, DSN integration point
- `.gitignore` - `.env`, `.env.backup`, `.env.production` ignored (lines 10-12)
- `.gitleaks.toml`, `Makefile` security targets, `.github/workflows/ci.yml` security job

## Flows reviewed

- Every HTTP response (success and 404): nosniff, Referrer-Policy, X-Frame-Options DENY, CSP applied by global middleware after `$next()`.
- Inbound `X-Request-Id` handling: attacker-controlled value only reused when it matches `\A[A-Za-z0-9._-]{8,128}\z` (AddCorrelationId.php:44), which prevents header/log injection (no whitespace, CR/LF, or control chars); otherwise replaced with a UUID.
- `GET /health` public exposure: returns only `ok/degraded/unreachable` and a timestamp; no version numbers, stack traces, or connection details leak on failure (exception swallowed at HealthController.php:31).

## Tests reviewed

- `tests/Feature/Ops/SecurityHeadersTest.php::security headers are set on every response` - asserts nosniff, Referrer-Policy, X-Frame-Options and CSP directives (default-src, frame-ancestors, object-src, base-uri, form-action)
- `tests/Feature/Ops/SecurityHeadersTest.php::security headers are set on error responses too` - headers survive the exception handler
- `tests/Feature/Ops/SecurityHeadersTest.php::the session cookie is http-only and same-site lax` - SEC-HEADERS-2
- `tests/Feature/Ops/SecurityHeadersTest.php::the session cookie is marked secure when configured for production` - SEC-HEADERS-2 prod flag
- `tests/Feature/Ops/CorrelationIdTest.php::an unsafe inbound request id is replaced by a generated uuid` - log-injection guard
- `tests/Feature/Ops/CorrelationIdTest.php::structured log lines are json and include the correlation id` - SEC-LOG-3 (ID present, no sensitive payload added)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make secrets` (gitleaks) | pass | 0 findings (executed in build log) |
| `make sast` (semgrep p/php + p/security-audit) | pass | 0 findings, 46 rules / 250 files (executed in build log) |
| `make audit` (composer + npm) | pass | 0 advisories (executed in build log) |
| `make osv` (osv-scanner) | pass | 0 (executed in build log) |
| `make sbom` (syft) | pass | `reports/sbom.cdx.json` generated (executed in build log) |
| `php artisan test --compact tests/Feature/Ops` | pass | 14/14 Ops tests incl. all header/cookie assertions (fresh run for this review) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation (SEC-TENANT) | n/a | No tenant-owned models/routes in Epic 00; isolation suite is Epic 03's named deliverable (ADR-0002 records the mechanism) |
| 2 | Authorization (SEC-AUTHZ) | n/a | No state-changing actions added; `/health` is read-only and intentionally public (epic implementation note), leaks no sensitive data |
| 3 | Authentication (SEC-AUTH) | n/a | Auth flows are Epic 02 scope; starter Fortify scaffolding untouched by this epic |
| 4 | Input & injection (SEC-INPUT) | ✅ | Only user input handled is the `X-Request-Id` header, strictly validated (AddCorrelationId.php:44); the single SQL statement is the constant `select 1`; no `{!! !!}` added |
| 5 | Customer tokens (SEC-TOKEN) | n/a | Epic 08 scope; design pre-recorded in docs/assumptions.md §Tokens |
| 6 | Secrets (SEC-SECRETS) | ✅ | gitleaks 0; `.env*` gitignored (.gitignore:10-12); `ERROR_TRACKING_DSN` empty in `.env.example:68`; no secrets in code |
| 7 | Dependencies (SEC-DEPS) | ✅ | composer/npm audit 0, osv-scanner 0, SBOM generated (build log); lockfiles committed |
| 8 | SAST | ✅ | semgrep 0 findings; `grep -rn nosemgrep app/ tests/ config/` returns nothing (verified in this review) |
| 9 | Headers & transport (SEC-HEADERS) | ⚠️ | All required headers set and tested (SetSecurityHeaders.php:36-39, SecurityHeadersTest); cookies HttpOnly + SameSite=lax + Secure-when-configured tested. Warning: CSP `script-src` includes `'unsafe-inline' 'unsafe-eval'` (F1) and no HSTS header (F2) |
| 10 | Sessions & CSRF (SEC-SESSION) | n/a | Framework defaults intact (`ValidateCsrfToken` in web group, untouched); login regeneration is Epic 02's test scope |
| 11 | Rate limiting (SEC-RATE) | n/a | No login/booking endpoints yet; health endpoint deliberately unthrottled, asserted by HealthCheckTest:40-51; limits documented in docs/assumptions.md §Rate limits |
| 12 | Logging & errors (SEC-LOG) | ✅ | Correlation ID validated before reuse, preventing log injection; structured channel logs only the message + shared context (CorrelationIdTest:38-53); health 503 hides exception detail (HealthController.php:27-33) |
| 13 | Uploads (SEC-UPLOAD) | n/a | No uploads in v1 (docs/assumptions.md) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | SEC-HEADERS-1 | `app/Http/Middleware/SetSecurityHeaders.php:18-19` | CSP allows `'unsafe-inline'` and `'unsafe-eval'` for scripts. The inline comment justifies it (Livewire/Alpine evaluate inline expressions; Flux injects inline fragments), and it is a real constraint of the chosen stack, but it weakens XSS mitigation. | Track for Epic 10 hardening: evaluate nonce-based CSP / `@cspNonce` support and dropping `'unsafe-eval'` once page inventory is final. Record in docs/assumptions.md deferred-findings log. |
| F2 | Low | SEC-HEADERS-3 | `app/Http/Middleware/SetSecurityHeaders.php:36-39` | No `Strict-Transport-Security` header. SEC-HEADERS-3 only assumes HTTPS in production (often terminated at the proxy), so this is hardening, not a violation. | Consider adding HSTS (prod-only) during Epic 10 hardening. |

## Required fixes (blocking)

- None. (F1 is Medium: justified by the server-rendered Livewire stack, documented inline, and tracked for hardening.)

## Final decision

**PASS WITH WARNINGS**

- Rationale: All Epic 00 security gates are green (secrets/SAST/audits/OSV/SBOM), SEC-HEADERS and SEC-LOG hold with test evidence, and input handling is safe; the CSP `unsafe-inline`/`unsafe-eval` allowance is a tracked Medium hardening item inherent to the Livewire stack.
- Blocking findings remaining: 0
