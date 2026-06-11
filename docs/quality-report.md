# LibreNexus, Final Quality Report

Date: 2026-06-11. Prepared per [specs/proof-package.md](../specs/proof-package.md)
at the end of Epic 10. Every number below comes from a tool run on the final
state; nothing is asserted without a gate or a named test behind it.

## What was built

All eleven epics from [specs/roadmap.md](../specs/roadmap.md) were implemented
in order, each committed only after its acceptance criteria, tests, gates, and
six structured reviews passed with zero blocking findings:

| Epic | Delivered | Review outcome |
|------|-----------|----------------|
| 00 Foundations | health check, correlation-ID JSON logging, security headers, failed-job store, ADRs | 6x pass, 0 blocking |
| 01 Public site | home, pricing, docs, open-source, privacy, imprint, footer, MIT license | 6x pass, 0 blocking |
| 02 Auth | register/login/reset/verify (queued mails), throttling, 2FA + passkeys tested, settings | 6x pass after fixes, 0 blocking |
| 03 Tenancy | fail-closed tenant scoping, roles, invitations, ownership lifecycle, named isolation suite | 6x pass after fixes, 0 blocking |
| 04 Staff & services | CRUD, membership linking, AA color palette, isolation extended | 6x pass, 0 blocking |
| 05 Slot engine | pure deterministic engine, DST both directions, availability editor | 6x pass after fixes, 0 blocking |
| 06 Booking | public flow, customer dedup, exclusion constraint, named concurrency suite, tokens | 6x pass after fixes, 0 blocking |
| 07 Appointments | list/calendar, manual create/reschedule/cancel, status matrix, cancellation mail | 6x pass after fixes, 0 blocking |
| 08 Self-service & comms | tokened cancel/reschedule with cut-off, reminders (idempotent), reschedule mail | 6x pass, 0 blocking |
| 09 Dashboard | metrics, onboarding checklist, demo seeder + demo login | 6x pass, 0 blocking |
| 10 Hardening | deferred findings closed, pagination, step throttles, config-driven password policy | this report |

Every MUST requirement in [specs/requirements.md](../specs/requirements.md) is
implemented and covered by at least one automated test. The SHOULD requirements
shipped too (2FA/passkeys, staff-service assignment, reminders, reschedule via
manage link, member removal, empty-state onboarding, demo seeder). The single
MAY item, booking approval (FR-BOOK-7), is implemented as a tenant setting with
the pending status flow.

## Skipped or reduced scope

- All v1 non-goals stand (no payments, SMS, calendar sync, native apps,
  recurring appointments, public API). Stretch goals (ICS export, per-tenant
  theming, analytics) were not attempted; gate quality was prioritized.
- UI language is English only; `locale` is stored but informational.
- Reminder emails carry no manage link: raw tokens are never stored (hash
  only), so the link cannot be reconstructed. The mail points to the link in
  the confirmation email instead.
- The full assumption log, kept current throughout the build, is
  [docs/assumptions.md](assumptions.md).

## Architecture summary

Server-rendered Laravel 13 monolith (PHP 8.4) with Livewire 4 + Flux UI,
PostgreSQL, database queues, Fortify auth. Three decisions carry the system
(ADRs in [docs/adr/](adr/)):

1. **Tenant = Team with a fail-closed global scope** (ADR-0002): every
   tenant-owned model opts into `BelongsToTenant`; without a tenant context
   queries match nothing, creates throw, and a spoofed `team_id` is rejected.
   An architecture test makes opting out impossible to miss, and
   `EnsureTeamMembership` (also Livewire-persistent) returns 404 to
   non-members so existence is never leaked.
2. **Double-booking prevented in the database** (ADR-0003): a partial GiST
   exclusion constraint on `(staff_id, tstzrange(buffered_starts_at,
   buffered_ends_at))` for time-reserving statuses. Booking and reschedule
   re-validate inside the same transaction and translate `23P01` into a
   friendly error; the named concurrency suite races two live PostgreSQL
   connections with an in-flight uncommitted conflict.
3. **A pure slot engine** (ARCH-STRUCTURE-3): no Eloquent inside; tenant-tz
   math with UTC results; DST gaps shorten windows, ambiguous times resolve to
   first occurrence; deterministic under any server timezone (tested with
   three).

## Test summary (final state)

| Layer | Count | Result |
|-------|-------|--------|
| Unit + feature (Pest, PostgreSQL) | 469 tests, 1500 assertions | 100% pass |
| Browser E2E (Pest 4 + Playwright) | 35 tests, 140 assertions | 100% pass |
| Named suite: `tests/Feature/Tenancy/IsolationTest.php` | 20+ tests | green since Epic 03 |
| Named suite: `tests/Feature/Booking/ConcurrencyTest.php` | 9 tests | green since Epic 06 |

## Tool results (final `make verify`, clean state)

| Gate | Threshold | Result |
|------|-----------|--------|
| QG-FORMAT (Pint) | 0 diffs | pass |
| QG-STATIC (PHPStan/Larastan level 7) | 0 errors, no baseline | pass, 0 errors |
| QG-COMPLEXITY / QG-DEADCODE (PHPMD) | 0 violations | pass |
| QG-DUPLICATION (jscpd) | < 3% | pass (~2%) |
| QG-DEPS-UNUSED / QG-DEPS-IMPLICIT | 0 | pass |
| QG-TESTS (Pest) | 100% pass | 469/469 |
| QG-COVERAGE | >= 80% overall, >= 95% critical | 97.2% overall; scoping, engine, booking, token classes 97-100% |
| QG-MUTATION | >= 70% overall, >= 85% critical | 98.2%; 4 survivors, each a verified equivalent (documented in-test) |
| QG-E2E (Pest browser) | all pass, no console errors | 35/35 |
| QG-SECRETS (gitleaks) | 0 findings | pass |
| QG-SAST (Semgrep p/php + p/security-audit) | 0 findings | pass |
| QG-DEPS-VULN (composer/npm audit + osv-scanner) | 0 high/critical | pass |
| QG-A11Y (pa11y-ci WCAG2AA + axe in E2E) | 0 errors | 11/11 public URLs; axe on every authenticated page family |
| QG-PERF (Lighthouse + N+1 tests) | perf >= 0.90, a11y >= 0.95, bp >= 0.90, seo >= 0.90 | all 11 URLs pass; query-count no-growth tests on lists, calendar, dashboard |
| SBOM (syft) | generated | `reports/sbom.cdx.json` |

The exact, reproducible command is `make setup && make verify` from a clean
checkout with PostgreSQL, Node, PHP 8.4, and the documented tools. CI runs
the same targets on every push; the first fully green run on the default
branch is
[run 27323217271](https://github.com/FabianWesner/librenexus/actions/runs/27323217271)
(all four jobs: static, tests + coverage + mutation, e2e + accessibility +
performance, security + SBOM).

## Security notes (SEC-*)

- **SEC-TENANT**: fail-closed central scoping, arch-test enforced, named suite
  covering route/ID/form/query/relationship vectors plus create-time spoofing,
  404 for non-members. Mutation score 100% on the scoping classes.
- **SEC-TOKEN**: 64-char CSPRNG tokens (more than 32 bytes entropy), SHA-256
  stored, exact-index lookup (no timing oracle), single-appointment scope,
  forged/cross tokens 404, raw token proven absent from logs by test.
- **SEC-AUTH/SESSION/RATE**: bcrypt, strict configurable password policy
  (pwned-check in strict mode), queued verification/reset mails, single-use
  expiring tokens, session regeneration asserted, login/2FA/reset/booking/
  manage endpoints throttled with clear, non-leaky messages.
- **SEC-INPUT**: all writes validated server-side; bindings everywhere; no
  `{!! !!}` on user content; mass assignment guarded plus the scope-level
  `team_id` spoof rejection.
- **SEC-HEADERS**: nosniff, strict referrer policy, frame denial, CSP. The CSP
  retains `unsafe-inline`/`unsafe-eval` for scripts: removing `unsafe-eval`
  was re-tested in Epic 10 and breaks Alpine/Livewire (27/35 browser tests
  fail). This is the documented, accepted trade-off of the chosen stack.
- Demo credentials (`demo@librenexus.test` / `password`, fixed manage token)
  are intentionally non-secret; the seeder refuses to run in production.

## Performance & reliability notes

- Public pages pass Lighthouse budgets (most at 0.94-1.0 performance) against
  a CI-equivalent server. Authenticated list/calendar/dashboard views are
  proven N+1-free by strict query-count equality tests.
- Booking and reschedule are atomic; the exclusion constraint is the final
  arbiter under concurrency (raced in tests). Reminders are idempotent via a
  conditional claim UPDATE, including a simulated mid-run race.
- Emails are queued (never inline) with scalar capture so queue workers never
  touch tenant-scoped models; failed jobs land in the inspectable store.
- NFR-PERF-1 (< 300 ms p95 server render) was spot-checked, not load-tested:
  warm responses measured ~85 ms on the booking page locally.

## Accessibility notes

- All 11 public URLs (marketing, legal, auth, demo booking, demo manage) pass
  pa11y WCAG 2.1 AA with zero errors and Lighthouse accessibility 1.0.
- Every authenticated page family (settings, tenant settings, staff, services,
  availability editor, appointments list/detail/new modal, calendar,
  dashboard in both states, manage page) carries an axe
  `assertNoAccessibilityIssues()` browser test.
- Contrast was fixed at the source (zinc-500 body text, 700-shade palette
  colors verified by a computed-contrast unit test, Flux stub overrides).

## Known limitations & remaining risks

1. **PHPMD does not scan Livewire SFC front-matter** (`resources/views`):
   complexity in page components is reviewed by humans and PHPStan, not PHPMD.
   Accepted tooling limitation; the largest components are tracked in the
   assumptions log.
2. **Four surviving mutants**, each manually verified equivalent and
   documented next to the test that would otherwise kill it.
3. **Capability URLs** (manage links) appear in mail bodies and server access
   logs by design; entropy makes guessing infeasible, and GETs are read-only.
4. **Single-process deployment assumptions**: database queue and scheduler
   need a worker and cron in production (standard Laravel ops; documented in
   the docs page).
5. **No load testing** was performed; NFR-PERF-1 rests on local spot checks
   and the N+1 guarantees.
6. Browser tests finish Flux dialog animations manually because headless
   Chrome renders no frames; this audits the real end-state DOM (documented in
   the tests).

## Honesty clause

- The progressive gates were intentionally not green on the starter skeleton
  (recorded baseline: coverage 76.8%); they were driven green epic by epic and
  the final pipeline passes end to end.
- `make performance`/`make accessibility` require a URL Chrome treats as a
  secure context. CI uses `127.0.0.1`; the local Herd HTTP domain fails
  Lighthouse's `is-on-https` audit and 404s `favicon.ico` (environment quirks,
  documented in the assumptions log).
- Reviews were conducted by structured-checklist agents against the
  review-checklists; their reports (with re-review sections where blocking
  findings were found and fixed) are in [docs/reviews/](reviews/). Eleven
  blocking findings were raised across the build and every one was fixed and
  re-verified, never waived.
- Nothing in this report was sampled; every gate ran over the whole codebase.

## Next steps (recommended)

1. Deploy a public demo and re-run Lighthouse/pa11y against production TLS.
2. Add ICS "add to calendar" links (stretch goal, cut for time).
3. Consider CSP nonces if Livewire/Alpine ship CSP-compatible builds.
4. Load-test booking under realistic concurrency (the constraint guarantees
   correctness; throughput is unmeasured).
5. Rotate the demo credentials story for any internet-facing demo install.

## Scorecard

| Category | Level | Evidence |
|----------|-------|----------|
| Functional completeness | **production-quality** | every MUST + all SHOULDs implemented and tested; FR-BOOK-7 (MAY) included |
| Test quality | **exceptional** | 504 tests, 97.2% coverage, 98.2% mutation with verified-equivalent survivors, named regression suites, genuine concurrency races |
| Code quality | **production-quality** | PHPStan L7 zero errors no baseline, PHPMD clean, duplication ~2%, no suppressions; SFC tooling blind spot documented |
| Architecture | **production-quality** | three load-bearing ADRs implemented and arch-test enforced; pure domain core; one deployable |
| Security | **production-quality** | fail-closed isolation, DB-level booking integrity, hashed capability tokens, all scanners clean; CSP trade-off documented |
| UX & accessibility | **production-quality** | WCAG2AA zero errors on all public URLs, axe on all authenticated families, styleguide followed |
| Performance & reliability | **solid MVP** | budgets met and N+1-proven; capped below production-quality because no load testing was performed |
| Documentation & reproducibility | **production-quality** | one-command benchmark, pinned tools, ADRs, assumption log, per-epic reviews, this report |
