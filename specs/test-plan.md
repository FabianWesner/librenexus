# LibreNexus — Test Plan

Every change is programmatically tested (project guardrail). Tests are the
primary evidence of correctness; reviews verify what tools cannot. This plan
defines the layers, coverage targets, conventions, and the named suites that
guard critical behavior.

## Test layers

| Layer | Tool | What it covers | Location |
|-------|------|----------------|----------|
| Unit | Pest | Pure logic: slot engine, value objects, enums, rules | `tests/Unit` |
| Feature | Pest + Laravel | HTTP/Livewire flows, validation, authorization, persistence | `tests/Feature` |
| Browser (E2E) | Pest 4 browser (Playwright) | Real user journeys, no JS/console errors | `tests/Browser` |
| Architecture | Pest `arch()` | Boundaries, no debug helpers, model/enum conventions, tenant scoping | `tests/Unit` or `tests/Arch` |

Most tests are **feature** tests (project guidance). Use factories and their
states; use `fake()` for data.

## Coverage & mutation targets

| Scope | Line coverage (QG-COVERAGE) | Mutation (QG-MUTATION) |
|-------|------------------------------|------------------------|
| Whole app | ≥ 80% | ≥ 70% |
| Critical domain logic | ≥ 95% | ≥ 85% |

**Critical domain logic** = slot engine (Epic 05), booking/concurrency
(Epic 06), tenant scoping (Epic 03), cancellation tokens (Epic 08). These use
`covers()`/`mutates()` so mutation testing targets them precisely.

Coverage must be **meaningful**: assertion-free tests that only execute lines
do not count — the mutation gate exists to catch exactly that.

## Named regression suites (must stay green for the rest of the build)

- **`tests/Feature/Tenancy/IsolationTest.php`** — SEC-TENANT. For each
  tenant-owned model, a member of tenant A is denied read/update/delete of
  tenant B's records via every entry point (Livewire actions, routes, ID in URL,
  relationship traversal). Extended as new models are added.
- **`tests/Feature/Booking/ConcurrencyTest.php`** — FR-BOOK-3. Concurrent /
  overlapping booking attempts for the same staff: exactly one succeeds; the DB
  ends with no overlap. Exercises the DB constraint / locking path, not just app
  checks.

## Accessibility & performance per page

QG-A11Y and QG-PERF are split by reachability (see
[quality-gates.md](quality-gates.md)). **Every epic that adds or alters a page is
responsible for wiring its page into the right mechanism** — this is part of that
epic's DoD (definition-of-done.md item 10), not an afterthought:

- **Public pages** (marketing/legal, the public booking flow, the seeded demo
  manage-token URL): add the URL to the `PUBLIC_URLS` list so `make accessibility`
  (pa11y-ci) and `make performance` (Lighthouse) cover it.
- **Authenticated/tokened pages** (dashboard, lists, calendar, settings, tenant
  pages, the live manage page): call Pest 4's `assertNoAccessibilityIssues()`
  (bundled axe-core) in the page's browser (E2E) test (logs in / uses a token;
  0 serious/critical violations), and assert no N+1 / within the query-count
  budget for list, calendar, and dashboard views.

| Epic | Pages added | a11y/perf mechanism |
|------|-------------|---------------------|
| 01 | marketing/legal | `PUBLIC_URLS` + Lighthouse |
| 02 | auth, user settings | axe in auth/settings E2E |
| 03 | tenant settings, accept-invite, switcher | axe in tenant-settings E2E |
| 04 | staff/service lists + forms | axe in E2E; N+1 check on lists |
| 05 | availability editor | axe in E2E |
| 06 | public booking flow, view-only manage | `PUBLIC_URLS` (booking + seeded manage token) + Lighthouse |
| 07 | appointments list, calendar, detail | axe in E2E; **N+1 check on list/calendar** |
| 08 | live manage (cancel/reschedule) | axe in E2E for the tokened page |
| 09 | dashboard, onboarding | axe in E2E; N+1 check; (public demo booking URL confirmed in `PUBLIC_URLS`) |

## What each epic must test (minimum)

- **Epic 00** — health-check (200 + 503), correlation-ID header, SEC-HEADERS +
  cookie flags, baseline arch tests.
- **Epic 01** — every public route 200 + no JS errors; footer links resolve;
  deferred demo/proof links resolve (no 404); a11y + perf on homepage/pricing.
- **Epic 02** — register/login/logout/reset/verify, login + reset throttling,
  profile/email-change/password, account-deletion incl. sole-owner block,
  2FA/passkeys.
- **Epic 03** — isolation suite, role-permission matrix, invitation lifecycle
  (incl. unregistered-invitee email match), tenant CRUD/switch, slug uniqueness +
  reserved-slug rejection, ownership transfer + sole-owner invariant.
- **Epic 04** — staff/service CRUD + validation (buffer 0 ok / negative rejected,
  duration/price bounds) + authorization; staff-link admin-only + ≤1-per-record;
  isolation extended; deactivated/archived excluded from bookable but kept.
- **Epic 05** — slot-engine unit suite with edge cases (DST, midnight,
  overlapping rules, buffers, lead time, horizon, non-UTC server tz);
  availability/time-off management + authorization; mutation on the engine.
- **Epic 06** — concurrency suite; full booking flow happy + each validation
  failure; status-reservation (cancelled/no_show does not block; freeing reopens
  slot); customer reuse by email per tenant; booking throttle; a11y + perf.
- **Epic 07** — view/filter with role visibility (own = linked staff record);
  manual create/reschedule/cancel incl. conflict attempt; status-transition
  matrix (valid + rejected terminal/invalid); cancellation mail enqueued;
  N+1 assertion.
- **Epic 08** — token security (valid/forged/cross-appointment); cancel +
  reschedule incl. cut-off; mail/queue assertions; reminder idempotency.
- **Epic 09** — dashboard metric correctness; onboarding state; seeder produces
  a bookable demo tenant; a11y + perf.
- **Epic 10** — full `make verify`; close coverage/mutation gaps from reviews.

## Conventions

- Create models via factories and their states; never hand-build models when a
  state exists.
- Use `RefreshDatabase` against PostgreSQL (tests run on `librenexus_test`).
- Use `Mail::fake()`, `Queue::fake()`, `Notification::fake()` for async
  assertions; assert jobs are **queued**, not sent inline (NFR-OPS-2).
- Run a subset for speed: `php artisan test --compact --filter=Name`. Run the
  full suite + gates before marking an epic done.
- Browser tests must assert no console errors (smoke) in addition to behavior.
- For timezone correctness, run slot-engine tests with a non-UTC app timezone.

## Edge cases that must have explicit tests

- DST spring-forward and fall-back around appointment times.
- Midnight / day-boundary slots.
- Back-to-back appointments with buffers (no overlap, no gaps lost).
- Service longer than any availability window → zero slots.
- Time off fully and partially covering a window.
- Minimum lead time and maximum horizon clamping.
- Cancellation exactly at the cut-off boundary.
- Booking a slot that was just taken (re-validation inside the transaction).
- A cancelled/no_show appointment must not block a new booking at the same time.
- Reschedule moves a held slot atomically without creating an overlap.
- Cross-tenant ID access for every tenant-owned model (incl. customers).
- Repeat booking with the same email reuses the tenant's customer record.
- Forged / wrong-appointment cancellation token.
- Sole-owner cannot leave/demote/delete-tenant/delete-account without transfer.
- Tenant slug equal to a reserved name (e.g. `pricing`, `login`, `book`) is
  rejected.
- Invalid appointment status transition (e.g. `cancelled → confirmed`) rejected.
