# Review Report — Security Reviewer — Epic 08 (Customer self-service & communication)

## Reviewed scope

- **Epic / change:** Epic 08 (token-actioned manage page, self-service actions, reminder command, new mailables)
- **Requirements/rules in scope:** SEC-TOKEN-1..3, SEC-LOG-1..3, SEC-TENANT, SEC-RATE, SEC-INPUT, SEC-SECRETS, SEC-DEPS, SAST

## Files reviewed

- `app/Actions/Booking/BookAppointment.php:68` — token generation: `Str::lower(Str::random(48)).bin2hex(random_bytes(8))`
- `app/Models/Appointment.php:99-110` — `findByManageToken`: SHA-256 hash, exact unique-index lookup, scope bypass justification
- `resources/views/pages/booking/⚡manage.blade.php` — `#[Locked]` token, `hydrate()` re-resolution + tenant re-set, throttle, server-side slot validation
- `app/Actions/SelfService/*` — no token handling beyond pass-through of the raw token into the mail URL; nothing logged
- `app/Console/Commands/SendAppointmentReminders.php` — `withoutGlobalScopes` leak audit, parameter-bound `whereRaw`
- `app/Mail/AppointmentRescheduledMail.php` — raw token only embedded in the customer-addressed mail URL; nullable on the admin path
- `database/migrations/2026_06_10_234907_create_appointments_table.php:31` — `cancellation_token_hash` unique index

## Flows reviewed

- Token resolution on every request — `mount()` and `hydrate()` both call `establishTenantContext()`, which resolves the appointment fresh by token hash and 404s when absent; Livewire actions therefore cannot run against a stale or foreign context. Verified that `cancel()`/`reschedule()`/`selectSlot()` all read `$this->appointment` (the re-resolved computed), never client state
- Cross-appointment and forged tokens — 404 / no data; token A acting on B impossible (the action only ever receives the appointment resolved from the caller's own token)
- Reminder console run — selection joined to each appointment's own (non-deleted) team; relations eager-loaded scope-free but only for the selected rows; mailable captures scalars at dispatch, so queue workers never query tenant data
- Mutation throttling — 20/min/IP checked first in both `cancel()` and `reschedule()`, generic non-leaky message

## Tests reviewed

- `tests/Feature/SelfService/TokenSecurityTest.php` — valid token works; forged and case-tampered tokens 404; token-for-A-cannot-act-on-B (B stays confirmed); terminal refusal; **raw token never appears in any log file after a full GET + cancel flow** (logs wiped, then scanned, lines 102-119)
- `tests/Feature/Booking/ManageTokenTest.php` — Epic 06 view-only token suite intact (not weakened)
- `tests/Feature/SelfService/CancelViaTokenTest.php:195` — 21st mutating request in a minute refused with a clear message, state unchanged
- `tests/Feature/Tenancy/IsolationTest.php` + `tests/Unit/TenantScopingTest.php` — named isolation suite green in the full run (442/442); no new tenant-owned models added

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make secrets` (gitleaks) | pass | no leaks found (14.12 MB scanned) |
| `make sast` (Semgrep p/php + p/security-audit) | pass | 46 rules, 378 files, 0 findings, no nosemgrep |
| `make audit` | pass | composer audit + npm audit: 0 advisories |
| `make osv` | pass | 177 composer + 447 npm packages, no issues |
| `make test` | pass | 442/442 incl. token, isolation, throttle suites |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation | ✅ | Isolation + scoping suites green; the two unscoped paths audited (6 `withoutGlobalScopes` call sites, all credential-keyed or row-self-joined, scalar-captured for the queue); reminder query excludes soft-deleted teams (`whereNull teams.deleted_at`) |
| 2 | Authorization | ✅ | The token is the capability; actions only ever operate on the token's own appointment; admin reschedule path unchanged (policy-gated, Epic 07) |
| 3 | Authentication | n/a | No auth surface touched |
| 4 | Input & injection | ✅ | `rescheduleDate` regex-validated; slot must come from the server-computed list (`selectSlot`), `selectedSlot` is `#[Locked]`; `whereRaw` parameter-bound; no `{!! !!}` on user content in the new views/mails |
| 5 | Customer tokens (SEC-TOKEN-1..3) | ✅ | ~309 bits CSPRNG entropy (48 lowercased alnum ≈ 245 bits + 8 random bytes hex ≈ 64 bits, both CSPRNG-backed), exceeds the 32-byte bar; stored SHA-256 only; lookup by exact hash equality on a unique index, so no character-timing oracle exists (the documented constant-time strategy, assumptions §Booking); single-appointment scope and forged/cross rejection tested; raw token never persisted or logged (asserted) |
| 6 | Secrets | ✅ | gitleaks clean; `.env` ignored; demo token allowlisted as documented non-secret |
| 7 | Dependencies | ✅ | audit + osv clean; SBOM target present (`make sbom`) |
| 8 | SAST | ✅ | Semgrep 0 findings, no suppressions |
| 9 | Headers & transport | ✅ | Unchanged from Epic 00; SecurityHeadersTest green in full run; restrictive referrer-policy mitigates token-in-URL referrer leakage |
| 10 | Sessions & CSRF | ✅ | Livewire mutations carry CSRF; no session changes in this epic |
| 11 | Rate limiting | ✅ | Mutating token actions throttled 20/min/IP with a clear message, tested; booking throttle unchanged |
| 12 | Logging & errors (SEC-LOG) | ✅ | Log-scan test proves no raw token; refusal messages generic (no other-tenant data); correlation-ID suite green; reminder command logs only a count |
| 13 | Uploads | n/a | None in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Low | SEC-TOKEN-2 (boundary of control) | `routes/web.php:20` (`/manage/{token}` as a GET path segment) | The capability URL inherently lands in browser history and any front-proxy/web-server access logs outside the app's control. Inherent to the email-link, no-login design (FR-CANCEL-1); app logs are proven clean and referrer-policy is restrictive | Defer: note in Epic 10 ops docs that production access logs containing manage URLs must be treated as sensitive (or shortened retention) |
| F2 | Low | SEC-RATE (hardening) | `⚡manage.blade.php` (GET path unthrottled) | Only the mutating actions are throttled; page GETs (token guessing surface) rely on entropy alone. With ~309-bit tokens brute force is computationally absurd, so this is hardening, not a hole | Defer to the Epic 10 abuse-hardening pass already tracked for the booking step actions (assumptions log line 269) |

## Required fixes (blocking)

- None.

## Final decision

**PASS**

- Rationale: SEC-TOKEN-1..3 hold with test evidence including the log-leak probe; tenant isolation is preserved through both scope-bypassing paths; all security gates ran clean in this review.
- Blocking findings remaining: 0
