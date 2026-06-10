# Review Report - QA - Epic 02 (Authentication & accounts)

## Reviewed scope

- **Epic / change:** Epic 02 (Authentication & accounts), current working tree
- **Requirements/rules in scope:** Epic 02 §Required tests; test-plan.md §Per-epic required tests (Epic 02) + §Coverage & mutation targets; QG-TESTS, QG-COVERAGE, QG-MUTATION, QG-E2E, QG-A11Y (authenticated pages via axe)

## Files reviewed

- `tests/Feature/Auth/AuthHardeningTest.php` - 10 new hardening tests (this epic's core additions)
- `tests/Feature/Auth/{Authentication,Registration,PasswordReset,EmailVerification,PasswordConfirmation}Test.php` - starter auth suite, kept green (26 auth feature tests total)
- `tests/Feature/Settings/{ProfileUpdate,Security}Test.php` - settings flows (12 tests, 1 with an empty body)
- `tests/Browser/AuthSettingsSmokeTest.php` - 7 browser tests with axe + JS-error assertions
- `tests/Pest.php`, `tests/TestCase.php` - RefreshDatabase wiring, helpers
- `phpunit.xml` - PostgreSQL test database

## Flows reviewed

- Register (success, duplicate email, weak password), login (success, invalid, throttle), logout, remember-me
- Reset (request, render, happy path, token single-use, token expiry, IP throttle)
- Verification (notice, signed link, invalid hash, idempotent revisit, gate redirect)
- Profile update, email-change re-verification + resend notification, password change, account deletion (+ wrong password)
- Browser: auth pages + verification notice + profile/appearance/security with axe, dark-mode persistence via localStorage-backed `$flux.appearance` re-checked after navigation, confirm-password UI flow

## Tests reviewed

- `AuthHardeningTest::login is throttled after repeated failures` - 6th attempt 429, still guest (AC-5)
- `AuthHardeningTest::a password reset token is single-use` / `an expired password reset token is rejected` - real token lifecycle incl. `travel()` past `auth.passwords.users.expire` (AC-3)
- `AuthHardeningTest::changing the email re-triggers verification` - asserts `email_verified_at` null **and** `VerifyEmail` notification sent (AC-6)
- `AuthHardeningTest::a user can stay logged in with remember me` - asserts the `remember_web_*` cookie and persisted `remember_token` (AC-2)
- `EmailVerificationTest::already verified user ... without firing event again` - idempotency, event-level assertion
- `ProfileUpdateTest::user can delete their account` - user gone + logged out
- `AuthSettingsSmokeTest::the appearance settings page ... persists the choice` - clicks Dark, re-navigates, asserts `document.documentElement.classList.contains("dark")` (FR-SETTINGS-2)
- `SecurityTest::two factor authentication disabled when confirmation abandoned between requests` - **empty body, zero assertions** (the suite's 1 risky test); see F1

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact` | pass | 105/105, 292 assertions, **1 risky** - fresh run |
| `make e2e` | pass | 14/14 browser tests, 40 assertions - fresh run |
| `make coverage` | **fail** | **78.4% < 80%** (up from the 76.8% Epic 00 baseline); auth-touched gaps: `TwoFactorLoginResponse` 0%, `FortifyServiceProvider` 66.7%, `AppServiceProvider` 60%, response classes 66.7% - fresh run |
| `make mutation` | not run | No `covers()`/`mutates()` attributes exist anywhere in `tests/` (grep), so the gate has no targets to measure (F3) |
| `make accessibility` | pass | pa11y 9/9 public URLs incl. the three auth pages - fresh run |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tests exist | ❌ | Epic required tests for register/login/logout/reset/verification/throttling/profile/email-change/password/deletion all exist and pass. Missing: 2FA enable/disable/challenge-completion and passkey register/verify ("Keep the existing 2FA/passkey tests green; **add any missing coverage**"; test-plan Epic 02 lists "2FA/passkeys"). The sole-owner deletion block in test-plan's Epic 02 line is explicitly re-scoped to Epic 03 by AC-6 (tracked note N1) (F1) |
| 2 | Right layer | ✅ | Flows feature-tested; journeys browser-tested with no-JS-error assertions; arch rules unit-level |
| 3 | Coverage | ❌/⚠️ | 78.4% overall vs 80% gate. Pre-tracked deferral from Epic 00/01 (epic-01 QA F3) and improved by +1.6pt, so the overall number stays a tracked Medium (F2). But the epic's own "Done when" requires auth coverage to meet the module target, and `TwoFactorLoginResponse` at 0% is an Epic 02 auth path with zero executions - that portion is part of F1 |
| 4 | Mutation | ⚠️ | No `covers()`/`mutates()` targets exist; epic-01's QA report expected "mutation targets begin with Epic 02+ domain code". Auth is not designated critical domain logic, so not blocking, but the convention is now one epic behind (F3) |
| 5 | Meaningful assertions | ❌ | All other tests assert real outcomes (cookies, tokens, notifications, DOM state). `SecurityTest.php:44` is an empty test body that passes while testing nothing - exactly the "coverage padding" the plan forbids (part of F1) |
| 6 | Edge cases | ✅ | Token re-use, token expiry, invalid verification hash, idempotent re-verification, wrong current password, throttle boundary (5 ok, 6th blocked) all covered; DST/booking edges are later epics |
| 7 | Named suites | n/a | Tenancy isolation (Epic 03) and booking concurrency (Epic 06) do not exist yet; nothing weakened |
| 8 | Factories & data | ✅ | `User::factory()` incl. `unverified()` and `withTwoFactor()` states; RefreshDatabase on PostgreSQL (phpunit.xml) |
| 9 | Async assertions | ✅/⚠️ | `Notification::fake()` + `assertSentTo` used for reset and re-verification. Note: these notifications are not actually queued in production (see Architecture/Performance F1 on inline email); once queued, add queue assertions |
| 10 | No skips | ✅ | Only conditional `markTestSkipped` guards on Fortify feature flags (all features enabled, so nothing skipped in the fresh run); no `only`/incomplete markers |
| 11 | Determinism | ✅ | Time control via `travel()`; no sleeps; browser tests use seeded users; suite is timezone-agnostic at this stage (slot-engine tz matrix arrives with Epic 05) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | AC-7 / Epic 02 §Required tests / QG-TESTS | `tests/Feature/Settings/SecurityTest.php`, missing tests | 2FA enable/confirm/disable, challenge completion (TOTP + recovery code) and passkey register/verify have no tests; `TwoFactorLoginResponse` 0% coverage proves the challenge path never runs; `SecurityTest.php:44` is an empty test body (the run's 1 risky test) that simulates coverage; `/reset-password/{token}` and `/two-factor-challenge` have no axe/browser check despite the epic's "axe assertions in the auth + settings E2E tests" requirement | Add the missing feature tests (Fortify endpoints: POST/DELETE `/user/two-factor-authentication`, confirm, challenge with TOTP + recovery code; passkey component test) plus browser/axe coverage for the reset-password and challenge pages; implement or delete the empty test. **Status: RESOLVED (verified in re-review)** |
| F2 | Medium | QG-COVERAGE | `make coverage` | Overall 78.4% < 80%; inherited deferral (76.8% at baseline, improved this epic), with the largest auth-side holes being the F1 paths plus `FortifyServiceProvider::teamInvitation` (lines 102-118) and the production password-rule branch | Fixing F1 closes most of the auth gap; keep the overall gate tracked and blocking at Epic 10 per the baseline rules |
| F3 | Medium | QG-MUTATION / test-plan convention | `tests/` (repo-wide) | No `covers()`/`mutates()` targets exist yet, so `make mutation` cannot measure anything; epic-01's review expected Epic 02+ code to start carrying them | Add `covers()` to the new auth tests (concerns, responses, limiters) when implementing F1; mandatory by Epic 05 where critical domain logic starts |
| F4 | Low | test hygiene | `tests/Feature/Settings/SecurityTest.php:7` | Empty `beforeEach(function () {});` left over | Delete it. **Status: RESOLVED (verified in re-review)** |
| N1 | Note | test-plan vs epic scope | test-plan.md Epic 02 line | "account-deletion incl. sole-owner block" conflicts with AC-6, which explicitly defers the sole-owner block to Epic 03; resolved in favor of the epic file | Cover the sole-owner block in Epic 03's suite |

## Required fixes (blocking)

- F1: add the missing 2FA/passkey tests, the challenge/reset-password page checks, and remove the empty placeholder test. *(Fixed - see re-review below.)*

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: the register/login/reset/verification/throttling/settings coverage is genuinely strong (meaningful assertions, real token lifecycles, axe-checked journeys, all fresh-run green), but the epic's required 2FA/passkey tests are missing, an empty test masquerades as that coverage (1 risky in the run), and the untested paths show up directly as 0%-covered auth code.
- Blocking findings remaining: 1 (F1)

## Re-review after fixes (2026-06-10)

Verified by reading the new tests and re-running every gate fresh:

- **F1 resolved.** `tests/Feature/Auth/TwoFactorAndPasskeyTest.php` (11 tests, 35 new assertions overall in the run) covers the full AC-7 matrix with meaningful, state-level assertions: secret created/confirmed/cleared in the DB, real TOTP codes via Google2FA, recovery-code consumption asserted against the decrypted remaining set, challenge throttle boundary (5 ok, 6th = 429), passkey list/delete through the Livewire page, cross-user deletion blocked, malformed passkey assertion not a 500, and both auth notifications asserted queued (`instanceof ShouldQueue`). The empty test is now a real behavioral test (abandoned unconfirmed setup discards the secret) and the empty `beforeEach` is gone (F4 closed). Browser suite gained `/reset-password/{token}` and `/two-factor-challenge` axe + no-JS-error checks, the latter reached through a genuine login redirect.
- Fresh runs: `php artisan test --compact` **116/116, 327 assertions, 0 risky**; `make e2e` **16/16, 46 assertions**; `make format-check`/`make static`/`make complexity` clean; duplication 1.78%.
- **F2 (coverage) remains open as a tracked Medium.** `make coverage` still fails the whole-app gate: **79.3% < 80%** (was 78.4% pre-fix, 76.8% at baseline). The epic's own auth gap is closed: `TwoFactorLoginResponse` went from 0% to 66.7% (the remaining miss is the same one-line `wantsJson` branch shared by all four response classes). The remaining sub-80 weight sits in Epic 03 teams scaffolding (`HasTeams`, `EnsureTeamMembership`, `Membership`, `TeamPolicy`, `Notifications/Teams/TeamInvitation` at 9.5%) plus the F2-of-security production-policy branch. Auth now meets its module bar; the overall gate stays a tracked deferral, blocking at Epic 10 per the baseline rules.
- **F3 (mutation targets) remains open as Medium**: a fresh grep still finds no `covers()`/`mutates()` in `tests/`; the convention must start no later than Epic 05's slot engine.
- Checklist updates: item 1 ✅, item 3 ⚠️ (tracked overall gate only), item 5 ✅, item 9 ✅ (queue assertions now real).

## Final decision

**PASS WITH WARNINGS**

- Rationale: every required Epic 02 test now exists, passes, and asserts real outcomes (0 risky), with browser/axe coverage for all auth pages; the two open items are the inherited whole-app coverage gate (79.3% vs 80%, now driven by Epic 03 scaffolding rather than this epic's code) and the not-yet-started mutation-target convention, both Medium and tracked.
- Blocking findings remaining: 0
