# LibreNexus, Assumptions & Scope Decisions

This document tracks every assumption made while building LibreNexus and any
scope deliberately reduced. It is kept current as the build progresses
(goal.prompt, Phase 1). Each entry names its source rule where one exists.

## Booking policy defaults (FR-TENANT-8)

Applied on tenant creation; editable by owner/admin in tenant settings.

| Setting | Default | Source |
|---------|---------|--------|
| Minimum lead time | 120 minutes (2 h) | FR-TENANT-8 (explicit) |
| Maximum booking horizon | 60 days | FR-TENANT-8 (explicit) |
| Cancellation cut-off | 120 minutes (2 h) before start | FR-TENANT-8 (explicit) |
| Reminder lead time | 24 h before start | FR-TENANT-8 (explicit) |
| Require booking approval | off (bookings are `confirmed` immediately) | FR-TENANT-8 / FR-BOOK-7 |

## Access-denial status: 404 for non-member tenant access (SEC-TENANT-4)

A non-member requesting any tenant-scoped resource receives **404**, not 403,
so the existence of tenants/records is not leaked. This is consistent across
routes, Livewire actions, and direct ID access. Authenticated members who lack
a role permission (e.g. staff editing services) receive **403**, since they
already know the resource exists. Documented choice per Epic 03 notes.

## Currency (FR-SERVICE-3)

- A tenant has a single `currency` setting (ISO 4217 code). Default: **EUR**
  (the tenant locale default is `en`; deriving currency from locale is fuzzy,
  so a fixed EUR default is used and the owner can change it in settings).
- Prices are integer minor units (cents). v1 services carry no own currency.

## Tenant & locale

- Default tenant timezone: **Europe/Berlin** for the demo seeder; new tenants
  must pick a timezone at creation (pre-filled with `UTC`).
- Locale is informational in v1 (UI is English only); stored per FR-TENANT-8.
- Personal tenant: created at registration (rather than "first login"; same
  observable result, simpler and atomic with account creation). Named
  "{First name}'s Office" with an auto-generated unique slug.

## Slots & scheduling (FR-AVAIL-3)

- Slot grid: slot starts are generated at **service duration + buffers**
  intervals from each availability window start (contiguous partitioning, no
  configurable grid step in v1). Buffer-before of the next appointment and
  buffer-after of the previous one both must fit inside availability windows.
- A slot must fit entirely (incl. buffers) inside one availability window;
  windows are not merged across rules unless they touch/overlap, in which case
  overlapping rules are unioned.
- "Any available" staff pick: the bookable staff member with the fewest
  time-reserving appointments that day, ties broken by lowest ID
  (deterministic, FR-BOOK-2 / Epic 06 AC-7).
- Times are stored UTC, displayed and computed in tenant timezone
  (ARCH-DATA-2). DST-skipped local times produce no slot; ambiguous (repeated)
  local times resolve to the first (earlier UTC) occurrence, matching Carbon's
  default.

## Availability & slots (Epic 05)

- Availability rule times are minute-precision strings in the tenant
  timezone; an end of `24:00` means end of day. Overlapping rules on the
  same weekday are rejected at input but unioned defensively by the engine.
- The PostgreSQL connection pins `timezone => UTC` (config/database.php) so
  timestamp storage is UTC regardless of the server's session timezone
  (ARCH-DATA-2); a feature test asserts Berlin 10:00 stores as 08:00 UTC.
- Three engine mutants are accepted as behaviorally equivalent, verified
  case by case and documented in tests/Unit/SlotEngineTest.php (zero-width
  window comparison; the two int casts on numeric strings). Every other
  engine mutant is killed (engine mutation 95%+).
- Livewire page-component implicit binding runs before route middleware sets
  the tenant context, so tenant-scoped route models are resolved in mount()
  after EnsureTeamMembership has run (availability editor pattern).

## Invitations (FR-TENANT-6)

- Invitations expire after **7 days** (spec says "expire" without a value).
- Invitation acceptance requires a logged-in user whose account email matches
  the invited email case-insensitively.

## Rate limits (SEC-RATE)

- Login: 5 attempts/minute per email+IP (Fortify default).
- Two-factor challenge: 5/minute (Fortify default).
- Password reset request: 5/minute per IP.
- Public booking submission: 10/minute per IP (generous enough for legitimate
  use, throttles scripted abuse). Confirmed slot validation happens inside the
  transaction anyway.
- Health endpoint: excluded from rate limiting (Epic 00 note).

## Tokens (SEC-TOKEN)

- Manage/cancellation token: 32 random bytes, base64url-encoded in the URL,
  stored as SHA-256 hash, looked up by hash (constant-time by construction:
  the hash lookup is an exact index match, and `hash_equals` is used for any
  direct comparison). One token per appointment, created at booking, not
  rotated on reschedule (the appointment identity stays the same).
- Demo seeder uses a fixed token so the `/manage/{token}` URL is stable for
  the pa11y/Lighthouse gates (Epic 09). Documented as intentionally
  non-secret demo data in `.gitleaks.toml` terms if flagged.

## Appointments (FR-APPT)

- Reschedule keeps the same appointment row (updates its time range) inside
  the same transactional/constraint path as booking; the manage token stays
  valid. An appointment whose status is terminal cannot be rescheduled.
- `completed` is set manually by staff/admin (no automatic completion job in
  v1); slot math treats past times as unavailable anyway.
- Pending appointments (approval mode) reserve time per FR-APPT-4.

## Customers (FR-CUST)

- Customer email is stored lowercased; uniqueness enforced per tenant by a DB
  unique index on (tenant_id, email).
- Repeat booking updates the stored name/phone with the latest values.

## Emails (FR-COMMS)

- Mail driver in dev/test/CI: `log`/array fake. No real SMTP is configured;
  production would set MAIL_* env vars. Queued via the database queue.
- Reminder idempotency: a `reminder_sent_at` timestamp on the appointment,
  set when the reminder job runs; the scheduled command only selects
  appointments with `reminder_sent_at IS NULL`.

## Scope reductions / non-goals confirmed

- All v1 non-goals from requirements.md stand (no payments, no SMS, no
  calendar sync, no native apps, no recurring appointments, no public API).
- Stretch goals (ICS export, approval workflow UI, theming, analytics) are
  attempted only if all gates are green and time remains; FR-BOOK-7 approval
  is implemented as a tenant setting + status flow (the spec marks it MAY,
  but the status model and policy field already require most of it).
- v1 has no user file uploads (SEC-UPLOAD-1: not applicable).
- UI language: English only.

## Tenancy (Epic 03)

- Multiple co-owners are legal (FR-TENANT-9 only demands "at least one
  owner"). Promoting a member to owner is allowed; "transfer ownership"
  demotes every other owner to admin so exactly one owner remains.
- Account deletion is blocked while the user is the sole owner of ANY
  non-personal tenant (the stricter reading of FR-TENANT-10), regardless of
  whether that tenant still has other members.
- Tenant slugs are stable: renaming a tenant never changes the slug
  (shared booking URLs keep working, FR-BOOK-1); the slug itself is editable
  in tenant settings with format, uniqueness, and reserved-name validation.
- Supported currencies in the settings UI: EUR, USD, GBP, CHF (ISO 4217;
  v1 list, easily extended). Locale list: English only (v1).
- AC-9's "removing a member linked to a staff record unlinks but preserves
  the staff record" can only be fully exercised once the Staff model exists;
  Epic 04 adds the staff-unlink-on-removal behavior and its test. The member
  removal itself (with last-owner guard) ships in Epic 03.
- `Membership` and `TeamInvitation` carry `team_id` but are deliberately NOT
  under the `BelongsToTenant` scope: they are the membership fabric itself,
  accessed before/while establishing tenant context (switcher, accept flow)
  and guarded by policies instead. The arch test allowlists exactly these
  two classes.

## Auth (Epic 02)

- Password policy (SEC-AUTH-2): production enforces min 12 chars, mixed case,
  numbers, symbols, and the compromised-password (uncompromised) check; other
  environments use the framework minimum of 8 so tests stay offline. The
  production branch lives in `AppServiceProvider::configureDefaults()`.
- The email-verification notice keeps Fortify's default URI `/email/verify`
  (named `verification.notice`); spec URLs are intent, not final strings
  (pages.md). The `email` top-level segment joins the reserved-slug set
  automatically via the route-derived reserved list.
- Throttle limits: login 5/min per email+IP, two-factor challenge 5/min per
  login session, password-reset link requests 5/min per IP (plus the framework
  per-email 60 s broker throttle). Throttled responses are the framework 429
  page.
- Full WebAuthn passkey ceremonies cannot run in feature tests; coverage =
  passkey listing/deletion, cross-user denial, invalid-assertion handling,
  and the vendor-tested Fortify passkeys feature. Browser-level passkey
  registration is not automated (documented limitation).

## Public site (Epic 01)

- The homepage's secondary CTA ("See a demo booking page") links to the docs
  booking section until the demo tenant exists (Epic 09), per the epic note
  that the link must never 404. Swapped to the seeded demo booking URL in
  Epic 09.
- The GitHub repository URL is `config('app.repository_url')`
  (`APP_REPOSITORY_URL` env); the placeholder default is confirmed when the
  proof package is published (Epic 10). External links are not asserted by
  tests; all internal links are.
- The imprint identifies the installation as a demo deployment; real
  operators replace it when self-hosting.

## Environment & tooling

- Local: Laravel Herd (https://librenexus.test), PostgreSQL on 127.0.0.1:5432
  (`librenexus` dev, `librenexus_test` tests). CI: GitHub Actions per
  `.github/workflows/ci.yml`.
- Coverage/mutation run via a pcov-enabled PHP (`COVERAGE_PHP` in Makefile).
- Lighthouse's `is-on-https` audit requires a secure context. CI serves on
  `127.0.0.1` (a secure context). Locally, run `make performance
  APP_URL=http://127.0.0.1:8000` against `php artisan serve`, or secure the
  Herd site (`herd secure`) and use `APP_URL=https://librenexus.test`. The
  plain Herd HTTP domain also 404s `/favicon.ico` (Herd proxy quirk, file
  serves fine via artisan), which trips the console-error audit; both are
  environment effects, not application defects.
- `make verify` thresholds are never modified (QG-* rule).

## Deferred findings log

Tracked per definition-of-done.md (Medium/Low only). Currently empty.

| Epic | Finding | Severity | Plan |
|------|---------|----------|------|
| 00 | CSP keeps `unsafe-inline`/`unsafe-eval` for Livewire/Alpine/Flux | Medium | Revisit tightening (nonces) in Epic 10 hardening; justified inline in `SetSecurityHeaders`. |
| 00 | Overall line coverage 76.8% (starter-kit baseline, < 80%) | Medium | 79.3% after Epic 02; remaining gap is Epic 03 teams scaffolding. Blocking at Phase 6 / Epic 10. |
| 01 | `APP_REPOSITORY_URL` placeholder 404s until repo is public | Medium | Confirm real URL in Epic 10 proof package. |
| 02 | `Password::defaults` branches on `isProduction()` (ARCH-CONFIG-2); strict policy untested | Medium | Move to config-driven policy or add a config-forced test in Epic 10. |
| 02 | Throttled logins show the framework 429 page, not an inline form message | Medium | Consider an inline message in Epic 10 polish (SEC-RATE-2 is still met: clear, non-leaky). |
| 02 | No explicit session-fixation regeneration assertion | Low | Add assertion in Epic 10 hardening (framework default behavior). |
| 03 | Member-role update logic lives inline in the teams edit component | Medium | Extract into an Action when Epic 04 touches member flows, or by Epic 10. |
| 03 | Team switcher reads one role query per team (small bounded 1+N) | Medium | Read role from the loaded pivot + add a query-count assertion in Epic 04. |
| 03 | Invitation accepted via GET; invite codes stored in plaintext | Low | Revisit in Epic 10 hardening (codes are 64-char random, single-use, expiring). |
| 03 | declineInvitation path untested; teams edit component oversized | Low | Cover/refactor by Epic 10. |
| 04 | AC-3 "past appointments remain" and AC-4 "slot engine respects assignment" provable only once appointments/slot engine exist | Medium | Prove with explicit tests in Epics 05 (assignment) and 06/07 (history retention). |
| 04 | Staff/service CRUD + link logic lives inline in Livewire SFC pages | Medium | Extract to Actions if the components grow again; revisit by Epic 10. |
| 04 | Pest browser server shares container state across requests (test-env limitation) | Low | Mitigated by the persistent-middleware regression test in the isolation suite. |
| 04 | formattedPrice assumes 2-decimal currencies | Low | Fine for the v1 currency list (EUR/USD/GBP/CHF); note for future currencies. |
| 05 | GetBookableSlots eager-loads time off unbounded by date range | Medium | Constrain by the requested window in Epic 06 before the public booking hot path. |
| 05 | Engine has no guard against a non-positive packing step (unreachable via app validation) | Low | Add defensive guard with Epic 06 booking wiring. |
