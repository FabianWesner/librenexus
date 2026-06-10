# Review Report - Performance - Epic 02 (Authentication & accounts)

## Reviewed scope

- **Epic / change:** Epic 02 (Authentication & accounts), current working tree
- **Requirements/rules in scope:** NFR-PERF-1/2/3, NFR-OPS-2, NFR-OBS (regression), QG-PERF; NFR-RELY-1 (booking atomicity) is Epic 06

## Files reviewed

- `app/Providers/FortifyServiceProvider.php` - rate limiters use the cache-backed `RateLimiter` (cheap per-request work); invitation lookup on login/register views is a single indexed `code`-unique query with `with('team')` eager load
- `app/Models/User.php`, `routes/web.php`, `routes/settings.php` - auth/settings pages, no list/calendar/dashboard queries added
- `resources/views/pages/auth/*.blade.php`, `pages/settings/*` - simple forms; no per-request heavy work
- `Makefile` - `PUBLIC_PATHS` extended with `/login /register /forgot-password` so the tool gates genuinely cover the new public pages
- `lighthouserc.json` - budgets: performance >= 0.90, a11y >= 0.95, best-practices >= 0.90, SEO >= 0.90
- `app/Notifications/` + repo grep for `ShouldQueue` - only `Teams\TeamInvitation` is queued; verification/reset emails are not (F1)

## Flows reviewed

- POST /register and POST /forgot-password - both send a notification email synchronously inside the request (framework `VerifyEmail`/`ResetPassword` are not queued); with a real SMTP transport these requests block on the mail server, breaking the NFR-PERF-1 300ms budget and NFR-OPS-2 (F1)
- Login/throttle path - limiter check is a cache hit; 429 short-circuits before authentication work
- Settings pages - operate on `Auth::user()` only; no N+1 surface (no collections rendered except the user's own passkeys, a single relation query)

## Tests reviewed

- `tests/Browser/AuthSettingsSmokeTest.php` - real-browser loads of auth + settings pages with no JS errors (pages are lightweight; no console noise)
- `tests/Feature/Auth/AuthHardeningTest.php::password reset link requests are throttled per ip` - abuse of the email-sending endpoint is capped at 5/min/IP, limiting the inline-email blast radius (mitigation, not a fix, for F1)
- No N+1/query-count assertion tests exist yet - correctly so, since the first list/calendar/dashboard views with relation loads arrive in Epics 04/07/09

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make performance` | pass (CI conditions) / env-artifact locally | Build run on `127.0.0.1:8123` (reports/lighthouse, today): 9/9 URLs pass all budgets; new pages /login /register /forgot-password score performance 0.95-0.99, a11y 1.0, best-practices 1.0, SEO 0.91. My fresh re-run over `http://librenexus.test` fails best-practices at 0.75 on **every** page (incl. unchanged Epic 01 pages) solely on `is-on-https`/`redirects-http` (plain-HTTP local domain; localhost is exempt) plus a Herd-only `favicon.ico` 404 (the file exists in `public/` and is served by artisan serve). Environment artifact, not app code (F2) |
| `make accessibility` | pass | pa11y 9/9 URLs, 0 errors - fresh run |
| `make e2e` | pass | 14/14, page loads with no JS errors - fresh run |
| `php artisan test --compact` | pass | 105/105 in 6.7s incl. full HTTP flows - indirect evidence requests are fast with the sync mail driver stubbed (log/array) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ✅ | No list/calendar/dashboard views added; invitation context eager-loads `team`; passkey list is one relation query |
| 2 | Query efficiency | ✅ | Limiters are cache-based; invitation lookup hits the unique `code` index; no per-row loops |
| 3 | Lighthouse budget | ✅ | 9/9 pages pass budgets on the canonical 127.0.0.1 run, incl. the three new auth URLs (cited above); local .test failure is HTTPS-environment noise (F2) |
| 4 | Server response budget | ✅/⚠️ | Auth/settings pages are trivial server-rendered forms; full feature suite (105 HTTP-level tests) completes in ~6.7s. Caveat: the budget only holds in production if emails stop being sent inline (F1) |
| 5 | Async | ❌ | Verification and reset emails are sent inline in the request (no `ShouldQueue`); NFR-OPS-2 says the app does not block on email (F1) |
| 6 | Reliability/concurrency | n/a | Booking atomicity/concurrency suite is Epic 06; nothing in this epic does multi-row writes outside the existing `DB::transaction` in `CreateNewUser` |
| 7 | Asset weight | ✅ | No new JS bundles; auth pages reuse the built app assets; Lighthouse performance 0.95+ on all three new pages |
| 8 | Caching | ✅ | Marketing pages unchanged; auth pages do no heavy per-request work |
| 9 | Observability | ✅ | Correlation-ID + structured logging (Epic 00) unchanged and their suite green in the fresh 105/105 run; failed-job visibility becomes relevant once emails are queued (F1 fix should land jobs in the standard `failed_jobs` path) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | NFR-OPS-2 / ARCH-ASYNC-1 / NFR-PERF-1 | register, resend-verification, email-change, forgot-password flows | Auth emails (`VerifyEmail`, `ResetPassword`) are dispatched synchronously inside the request; under a real SMTP transport these endpoints block on the mail server (typically hundreds of ms to seconds), violating the explicit "the app does not block on email" rule and the 300ms p95 budget. The performance checklist names inline email sending as a failing condition | Queue both notifications (ShouldQueue overrides) and assert queueing in the auth tests; shared finding with Architecture F1. **Status: RESOLVED (verified in re-review)** |
| F2 | Low | QG-PERF (environment) | local `make performance` over `http://librenexus.test` | Best-practices 0.75 on all pages from `is-on-https`/`redirects-http` (plain-HTTP .test domain) plus a Herd-specific `favicon.ico` 404 (file exists; artisan serve returns it); the canonical CI-style 127.0.0.1 run passes 9/9 | None for app code; note for Epic 10: run the gate over HTTPS or 127.0.0.1 and re-verify favicon delivery on the demo deployment |

## Required fixes (blocking)

- F1: queue the verification and password-reset emails so no request blocks on mail. *(Fixed - see re-review below.)*

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: page-level performance is excellent (all nine public URLs pass Lighthouse budgets under canonical conditions, no N+1 surface, cheap limiter checks), but the epic's new email-sending endpoints do that work inline in the request, which the performance checklist and NFR-OPS-2 explicitly treat as a blocking defect; the fix is a small, well-understood change.
- Blocking findings remaining: 1 (F1)

## Re-review after fixes (2026-06-10)

Verified by reading the new code and re-running the suites fresh:

- **F1 resolved.** `QueuedVerifyEmail` and `QueuedResetPassword` (`app/Notifications/Auth/`) implement `ShouldQueue` + `Queueable`, and `User` overrides `sendEmailVerificationNotification`/`sendPasswordResetNotification` to dispatch them, so registration, email-change, resend-verification and forgot-password requests now enqueue mail instead of blocking on the transport. Proven by `TwoFactorAndPasskeyTest::the verification notification is queued` and `::the password reset notification is queued` (both assert `instanceof ShouldQueue`); a repo grep confirms every notification the app sends by mail is now queued. Failed deliveries surface via the standard `failed_jobs` table (NFR-OBS-4). Checklist items 5 and 9 are now ✅, and the item-4 caveat is removed.
- Fresh runs: `php artisan test --compact` 116/116 in ~6.9s; `make e2e` 16/16 (the two new browser tests load `/reset-password/{token}` and `/two-factor-challenge` with no JS errors). Lighthouse evidence unchanged: nothing in the fix touches pages, assets or queries, and the canonical 127.0.0.1 run remains 9/9 within budgets.
- F2 (Low, plain-HTTP `.test` Lighthouse artifact + Herd favicon 404) remains a tracked environment note for Epic 10.

## Final decision

**PASS WITH WARNINGS**

- Rationale: the only blocking issue (inline email sending) is fixed with queued notifications and test-asserted; budgets, query efficiency and async behavior are all green, leaving one Low environment note for the Epic 10 demo deployment.
- Blocking findings remaining: 0
