# Review Report - Security - Epic 02 (Authentication & accounts)

## Reviewed scope

- **Epic / change:** Epic 02 (Authentication & accounts), current working tree
- **Requirements/rules in scope:** SEC-AUTH-1..5, SEC-RATE-1/2, SEC-SESSION-1..3, SEC-INPUT-1..4, SEC-SECRETS, SEC-DEPS, SEC-HEADERS (regression), QG-SECRETS, QG-SAST, QG-DEPS-VULN. SEC-TENANT/SEC-AUTHZ role matrix arrive with Epic 03; SEC-TOKEN with Epic 08; SEC-UPLOAD n/a in v1

## Files reviewed

- `app/Models/User.php` - `password => 'hashed'` cast (bcrypt via framework), `#[Hidden]` on password/2FA secrets/remember_token, `MustVerifyEmail` activated (SEC-AUTH-1/3)
- `app/Providers/AppServiceProvider.php` - `Password::defaults`: production min 12 + mixed case + numbers + symbols + `uncompromised()`; framework min 8 fallback otherwise (SEC-AUTH-2)
- `app/Providers/FortifyServiceProvider.php` - limiters: `login` 5/min by email+IP, `two-factor` 5/min by login session id, `password-reset` 5/min by IP (SEC-RATE-1)
- `routes/web.php` - POST /forgot-password re-registered with `guest` + `throttle:password-reset` (Fortify only consults its limiter config for login/two-factor); inline comment documents the replacement
- `config/fortify.php` - `limiters` maps `login` and `two-factor`; 2FA with `confirm` + `confirmPassword`; passkeys with `confirmPassword`
- `app/Actions/Fortify/*`, `app/Concerns/{Password,Profile}ValidationRules.php` - server-side validation for register/reset/profile
- `routes/settings.php` - settings behind `auth` (+ `verified`; security page behind `password.confirm`)
- `resources/views/pages/auth/*.blade.php`, `pages/settings/*` - no `{!! !!}` on user content (grep clean), CSRF-protected forms (framework `web` middleware, not disabled)
- `database/migrations/2026_06_10_*` - 2FA secret/recovery-code columns (encrypted by Fortify), passkeys table

## Flows reviewed

- Brute-force login: 5 failures then 429 for the same email+IP; reset-link spray: 5 requests then 429 per IP (SEC-RATE-1)
- Password reset: token single-use and expiry enforced (SEC-AUTH-4); reset applies `Password::default()` rules server-side
- Email verification: signed, time-limited URL; invalid hash rejected; revisit after verification is idempotent and does not re-fire the event (SEC-AUTH-4)
- Verification gate: unverified user redirected from `verified` routes (SEC-AUTH-3 / FR-AUTH-4)
- Logout: session no longer authenticated (SEC-SESSION-3); login regeneration is Fortify framework behavior, not bypassed (custom code only overrides views/responses/limiters)
- Account deletion and password change require the current password (no privilege shortcut)
- Mass assignment: `#[Fillable(['name','email','password','current_team_id'])]` on User; all writes go through validated actions (SEC-INPUT-4)

## Tests reviewed

- `tests/Feature/Auth/AuthHardeningTest.php` - login throttle 429, reset-link throttle 429, token single-use, token expiry, weak-password rejection, unverified redirect, email-change re-verification (SEC-AUTH-2/3/4, SEC-RATE-1)
- `tests/Feature/Auth/EmailVerificationTest.php` - invalid hash rejected; idempotent re-verification
- `tests/Feature/Auth/AuthenticationTest.php` - invalid credentials produce a field error (non-leaky), logout leaves guest, 2FA users redirected to the challenge
- `tests/Feature/Settings/{ProfileUpdate,Security}Test.php` - current-password requirement for delete + password change; confirm-password gate on the security page
- `tests/Feature/Ops/SecurityHeadersTest.php` (Epic 00 suite, still green) - nosniff/Referrer-Policy/CSP and HttpOnly/SameSite/Secure cookie flags (SEC-HEADERS regression)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make secrets` | pass | gitleaks: no leaks found - fresh run |
| `make sast` | pass | Semgrep p/php + p/security-audit: 46 rules, 271 files, 0 findings - fresh run |
| `make audit` | pass | composer audit 0 advisories; npm audit 0 vulnerabilities - fresh run |
| `make osv` | pass | osv-scanner over both lockfiles: no issues - fresh run |
| `php artisan test --compact` | pass | 105/105 incl. all throttle/token/session-adjacent tests - fresh run (1 risky = empty 2FA test, see F1) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation (SEC-TENANT) | n/a | No tenant-owned models added; isolation suite lands in Epic 03 |
| 2 | Authorization (SEC-AUTHZ) | ✅ | All settings mutations operate only on `Auth::user()` and require current password / password confirmation; no IDs accepted from the client for these flows, so no IDOR surface added |
| 3 | Authentication (SEC-AUTH) | ⚠️ | SEC-AUTH-1 hashed cast + hidden secrets ✅; SEC-AUTH-2 server-side policy ✅ (but the strict production rule set is never executed in any environment that runs tests, F2); SEC-AUTH-3 gate ✅ (tested); SEC-AUTH-4 single-use + time-limited tokens ✅ (tested); SEC-AUTH-5 "remain functional **and tested**" ❌ for 2FA enable/disable/challenge completion and passkeys (F1) |
| 4 | Input & injection (SEC-INPUT) | ✅ | Validation via Fortify actions/Livewire rules; no raw SQL (Semgrep + grep); no `{!! !!}` on user content; explicit `#[Fillable]` |
| 5 | Customer tokens (SEC-TOKEN) | n/a | Epic 08 |
| 6 | Secrets (SEC-SECRETS) | ✅ | gitleaks clean; `.env` git-ignored; config env-driven |
| 7 | Dependencies (SEC-DEPS) | ✅ | composer/npm audit + osv clean; lockfiles committed; SBOM target available (`make sbom`) |
| 8 | SAST | ✅ | Semgrep 0 findings, no `nosemgrep` markers in the epic's files |
| 9 | Headers & transport (SEC-HEADERS) | ✅ | Epic 00 `SetSecurityHeaders` + SecurityHeadersTest still green; cookie HttpOnly/SameSite/Secure asserted by test |
| 10 | Sessions & CSRF (SEC-SESSION) | ⚠️ | CSRF framework default intact (not disabled anywhere); logout leaves guest (tested). Session regeneration on login is provided by Fortify and not bypassed, but no explicit regeneration assertion exists (F3, Low) |
| 11 | Rate limiting (SEC-RATE) | ✅ | Login and reset-link throttles defined, applied and proven by 429 tests; two-factor limiter mapped in config; booking throttle correctly deferred to Epic 06 |
| 12 | Logging & errors (SEC-LOG) | ✅ | No password/token logging in app code; invalid-login error is generic; correlation-ID middleware (Epic 00) unchanged |
| 13 | Uploads (SEC-UPLOAD) | n/a | No uploads in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | SEC-AUTH-5 / AC-7 | `tests/Feature/Settings/SecurityTest.php` | SEC-AUTH-5 requires 2FA and passkeys to remain functional **and tested**. There is no test for 2FA enable/confirm/disable, no challenge-completion test (TOTP or recovery code; `TwoFactorLoginResponse` has 0% coverage), and no passkey register/verify test. The test at `SecurityTest.php:44` has an empty body (the suite's 1 risky test), so a regression in these security-critical flows would ship undetected | Add the missing 2FA/passkey flow tests (shared finding with QA/Product); implement or remove the empty test. **Status: RESOLVED (verified in re-review)** |
| F2 | Medium | SEC-AUTH-2 | `app/Providers/AppServiceProvider.php:41-46` | The min-12 + uncompromised production password policy lives behind an `isProduction()` branch and is never executed by the test suite; a defect in the strict rule set would only surface in production | Make the policy config-driven and add a test that runs with the strict rules enabled; defer to Epic 10 with a tracked note |
| F3 | Low | SEC-SESSION-1 | login flow | No explicit test asserts the session ID is regenerated on login; the behavior comes from unmodified Fortify internals | Add a regeneration assertion to the auth suite when convenient (Epic 10 hardening) |

## Required fixes (blocking)

- F1: test the 2FA enable/disable/challenge and passkey flows so SEC-AUTH-5 is actually verified. *(Fixed - see re-review below.)*

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: no vulnerability, leak or weakness was found; hashing, password policy, token lifetimes, the verification gate, throttling, headers, sessions and all security tool gates are verified green. The block is SEC-AUTH-5: the 2FA/passkey flows this rule protects are effectively untested, and one placeholder test pretends otherwise, which the project's honesty bar does not allow to pass.
- Blocking findings remaining: 1 (F1)

## Re-review after fixes (2026-06-10)

Verified by reading the new tests and re-running the suites fresh:

- **F1 resolved.** `tests/Feature/Auth/TwoFactorAndPasskeyTest.php` (11 tests) now proves SEC-AUTH-5 against the real Fortify endpoints: enable + TOTP confirm (Google2FA-generated codes against the stored encrypted secret), wrong-code confirm rejected, disable clears the secret, challenge completion via TOTP and via recovery code with consumption asserted (single-use recovery codes), invalid challenge code leaves the user guest, and the challenge itself is throttled (429 after 5 failures, closing the previously untested `two-factor` limiter path). The formerly empty test now verifies that an abandoned unconfirmed setup discards the pending secret (no stale secrets linger). Security-positive extras: a cross-user passkey deletion attempt is blocked (`ModelNotFoundException`, IDOR-style check, SEC-AUTHZ-3) and a malformed passkey login assertion fails without a 500. The previously dead `TwoFactorLoginResponse` is now bound and exercised.
- Checklist updates: item 3 (SEC-AUTH) now ✅ except the F2 production-policy caveat; item 11 (SEC-RATE) additionally covers the two-factor challenge.
- Fresh runs: `php artisan test --compact` 116/116, 0 risky; `make format-check`/`make static` clean. Secrets/SAST/audit/OSV were verified clean earlier the same day and no dependency or config surface changed since (the new code is tests + two notification subclasses + one container binding).
- F2 (Medium, production password policy unexercised by tests) and F3 (Low, no explicit session-regeneration assertion) remain open and tracked for Epic 10.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every SEC-* rule in scope now holds with test evidence, including SEC-AUTH-5 which blocked the first pass; remaining items are Medium/Low hardening notes (config-driven password policy + a session-regeneration assertion), tracked for Epic 10.
- Blocking findings remaining: 0
