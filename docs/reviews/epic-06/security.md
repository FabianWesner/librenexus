# Review Report - Security - Epic 06 (Public booking & concurrency)

## Reviewed scope

- **Epic / change:** Epic 06, working tree on `main` (uncommitted Epic 06 increment)
- **Requirements/rules in scope:** SEC-TENANT-1..3, SEC-TOKEN-1..3, SEC-INPUT-1..4, SEC-RATE-1/2, SEC-SECRETS, SEC-DEPS, SEC-LOG; QG-SECRETS, QG-SAST, QG-DEPS-VULN

## Files reviewed

- `app/Actions/Booking/BookAppointment.php` - token generation (`Str::lower(Str::random(48)).bin2hex(random_bytes(8))`), sha256-only storage, validated DTO input
- `app/Models/Appointment.php` - `findByManageToken` (hash lookup, `withoutGlobalScopes` justification), fillable list
- `app/Models/Customer.php` - email normalization, fillable list
- `app/Concerns/BelongsToTenant.php` - team_id spoof guard on create (SEC-INPUT-4 backstop)
- `app/Http/Middleware/ResolvePublicTenant.php` - fail-closed tenant context on the public route
- `resources/views/pages/booking/⚡show.blade.php` - honeypot, RateLimiter, `#[Locked]` state, server-side rules, `hydrate()` context
- `resources/views/pages/booking/⚡manage.blade.php`, `⚡confirmed.blade.php` - token-gated reads, slug cross-check
- `app/Mail/AppointmentConfirmationMail.php` + `resources/views/mail/appointments/confirmation.blade.php` - where the raw token travels
- `database/seeders/DemoSeeder.php` - the documented non-secret `demo-manage-token`
- `routes/web.php` - `/manage/{token}` static before the catch-all
- `docs/assumptions.md` §Cancellation tokens + §Booking - recorded token/throttle decisions

## Flows reviewed

- Token lifecycle: generated from CSPRNG inside the booking transaction (48 base62-lowercased chars ~248 bits + 16 hex chars 64 bits, > 32 bytes entropy); only `hash('sha256', $raw)` persisted; raw exists in memory, the queued mail, and the confirmed/manage URLs; lookup is an exact unique-index match on the hash (constant-time by construction, no timing oracle)
- Cross-tenant probes: foreign service id on the booking page -> ModelNotFoundException (tenant scope); foreign tenant slug on the confirmed URL -> 404; forged token -> 404; token A never renders appointment B
- Bot/abuse path: filled honeypot drops silently with no error; 11th confirm attempt per IP per minute rejected with a non-leaky message; both before any insert
- Mass assignment: `team_id` is fillable but never sourced from request input; `BelongsToTenant::creating` throws on a team_id differing from the active tenant (spoof guard); all customer-controlled fields pass `detailRules()` first

## Tests reviewed

- `tests/Feature/Tenancy/IsolationTest.php::customers and appointments isolation (Epic 06)` - tenant B customers/appointments invisible and findOrFail-fails under tenant A (SEC-TENANT-1); suite run fresh, green
- `tests/Feature/Booking/ManageTokenTest.php` (5) - valid token resolves, forged token 404s, token scoped to exactly one appointment, confirmation page 404s under a foreign slug (SEC-TOKEN-3)
- `tests/Feature/Booking/BookingHardeningTest.php::the manage token carries at least 32 bytes of entropy in a fixed format` - format `/\A[a-z0-9]{48}[a-f0-9]{16}\z/`, hash matches storage, uniqueness across bookings (SEC-TOKEN-1)
- `tests/Feature/Booking/BookingFlowTest.php` - honeypot, throttle (SEC-RATE-1/2), every validation failure persists nothing (SEC-INPUT-1), cross-tenant service rejected
- `tests/Feature/Booking/PublicRoutingTest.php` - reserved slugs cannot shadow static/system routes

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make secrets` | pass | gitleaks: no leaks (13.85 MB scanned) |
| `make sast` | pass | Semgrep p/php + p/security-audit: 0 findings on 349 files, no `nosemgrep` |
| `make audit` | pass | composer + npm audit clean |
| `make osv` | pass | "No issues found" |
| `vendor/bin/pest tests/Feature/Booking tests/Feature/Tenancy` | pass | 124/124, 404 assertions (isolation + token suites) |
| `grep` for raw-token logging | pass | no `Log::`/`logger()` call touches `rawToken`/`manageUrl`; only correlation-id context is logged (SEC-TOKEN-2, SEC-LOG) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Tenant isolation | ✅ | Named suite extended to Customer + Appointment and run fresh (green); fail-closed scope on the public route via ResolvePublicTenant; Livewire updates re-establish context in `hydrate()` |
| 2 | Authorization | ✅ | Public flow is intentionally unauthenticated; every read is tenant- or token-scoped; `#[Locked]` on all flow state prevents client-side tampering; IDOR probed (foreign service id, foreign slug, forged token - all fail) |
| 3 | Authentication | n/a | No auth surface changed; customers never authenticate (FR-CUST-3) |
| 4 | Input & injection | ✅ | `detailRules()` validates name/email/phone/notes server-side; slot/service/staff re-validated server-side against the engine (never trusts the shown slot); no raw interpolated SQL in app code (constraint DDL is static; ConcurrencyTest helpers interpolate only self-generated test values); no `{!! !!}` in booking views |
| 5 | Customer tokens | ✅ | > 32 bytes CSPRNG entropy, sha256-only storage, exact-hash index lookup, single-appointment scope - each asserted by tests; demo token documented as intentionally non-secret (assumptions.md, DemoSeeder docblock) |
| 6 | Secrets | ✅ | gitleaks clean; no new env values |
| 7 | Dependencies | ✅ | audit + osv clean; SBOM present (`reports/sbom.cdx.json`) |
| 8 | SAST | ✅ | Semgrep 0 findings, no suppressions |
| 9 | Headers & transport | ✅ | Global `SetSecurityHeaders` middleware applies to the new public routes (header feature test from Epic 01 unchanged and green in `make test`) |
| 10 | Sessions & CSRF | ✅ | Livewire mutations carry CSRF by framework default; nothing disabled |
| 11 | Rate limiting | ✅/⚠️ | Confirm action throttled 10/min/IP with a clear, non-leaky message, tested; see F1 for the unthrottled earlier steps |
| 12 | Logging & errors | ✅ | No token/PII logging found; correlation ID middleware intact |
| 13 | Uploads | n/a | None in v1 |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | SEC-RATE-1 | `⚡show.blade.php::confirmBooking` | Only the final confirm action consumes the rate limiter. The cheaper-to-call step actions (`chooseStaff`/`selectDate` trigger full-horizon engine computation per request) and validation-failing submissions are unthrottled, leaving a computational-abuse vector on the public endpoint | Add a coarse per-IP throttle on the public booking routes/Livewire updates (Epic 10 hardening); the double-booking guarantee is unaffected |
| F2 | Low | SEC-TOKEN-2 | `routes/web.php` (`booking.confirmed`, `booking.manage`) | The raw token travels in URLs (by spec design for `/manage/{token}`), so it can land in webserver access logs and browser history; SEC-TOKEN-2 is honored for application logs/analytics (verified none). The confirmed page additionally exposes it in the address bar right after booking | Accepted by design (spec defines tokened URLs); revisit in Epic 10 if access-log hygiene is in scope |
| F3 | Low | SEC-RATE-1 | `⚡show.blade.php::confirmBooking` | `RateLimiter::attempt` is keyed on `request()->ip()`; behind a misconfigured proxy all customers would share one bucket (availability, not bypass, risk) | Confirm trusted-proxy config at deploy time; no code change needed now |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: tenant isolation and token security - the two highest-stakes items - are enforced server-side and proven by fresh named-suite runs; all security gates are green. The Medium finding is an abuse-hardening gap on a public endpoint, tracked for Epic 10, not a data-exposure or integrity issue.
- Blocking findings remaining: 0
