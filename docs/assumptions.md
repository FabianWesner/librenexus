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
| 00 | Overall line coverage 76.8% (starter-kit baseline, < 80%) | Medium | Closed progressively per epic; blocking at Phase 6 / Epic 10 per quality-gates baseline rules. |
