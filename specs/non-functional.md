# LibreNexus — Non-Functional Requirements

NFRs are first-class quality gates. Where a tool can measure them they map to a
gate in [quality-gates.md](quality-gates.md); otherwise the relevant reviewer
verifies them with evidence.

## NFR-PERF — Performance

- **NFR-PERF-1** Server response time budget: typical authenticated page and
  public booking page render server-side in **< 300 ms** p95 under local/CI
  conditions with the demo dataset. *(No dedicated tool gate; this is a known
  manual check — verified by the Performance reviewer via timing/log evidence and
  supported by the N+1/query-count tests of NFR-PERF-2.)*
- **NFR-PERF-2** No N+1 queries on list/calendar/dashboard views; verified by a
  query-count assertion test.
- **NFR-PERF-3** Public pages meet the Lighthouse budget (QG-PERF): performance
  ≥ 0.90.
- *Verified by:* QG-PERF, N+1 assertion tests, Performance review.

## NFR-A11Y — Accessibility

- **NFR-A11Y-1** Public pages and key app flows meet WCAG 2.1 AA (QG-A11Y).
- **NFR-A11Y-2** Forms have labels, errors are announced, focus order is logical,
  and the app is keyboard-navigable.
- **NFR-A11Y-3** Color is never the only signal (status uses text/icon too).
- *Verified by:* QG-A11Y (pa11y), Lighthouse a11y ≥ 0.95, QA review.

## NFR-RELY — Reliability

- **NFR-RELY-1** Booking and reschedule are atomic; partial failures never leave
  orphaned or double-booked appointments (DB transaction + constraint).
- **NFR-RELY-2** Queued jobs (emails, reminders) are retriable and idempotent;
  failures are visible.
- **NFR-RELY-3** The slot engine is deterministic and timezone-correct.
- *Verified by:* concurrency test, idempotency tests, Architecture/QA review.

## NFR-OBS — Observability

- **NFR-OBS-1** Structured (JSON) logging in production format.
- **NFR-OBS-2** A correlation ID per request, propagated to logs and returned in
  a response header.
- **NFR-OBS-3** Error-tracking readiness: a single integration point (e.g. a
  configurable DSN) so an error tracker can be enabled without code changes
  (integration itself out of scope).
- **NFR-OBS-4** Background-job visibility: failed jobs are recorded and
  inspectable.
- *Verified by:* logging/correlation tests (Epic 00), Architecture review.

## NFR-MAINT — Maintainability

- **NFR-MAINT-1** Code passes all code-quality gates (format, static, complexity,
  duplication, dead code).
- **NFR-MAINT-2** Architecture boundaries (see [architecture.md](architecture.md))
  hold; enforced by `arch()` tests where possible.
- **NFR-MAINT-3** Naming is descriptive and consistent; domain language matches
  [requirements.md](requirements.md) glossary.
- *Verified by:* code-quality gates, arch tests, Code Quality review.

## NFR-REPRO — Reproducibility

- **NFR-REPRO-1** `make setup && make verify` runs green from a clean checkout
  (QG-CLEAN-CHECKOUT).
- **NFR-REPRO-2** Dependencies are pinned via committed lockfiles; tool versions
  are documented/pinned (e.g. phar versions in the Makefile).
- **NFR-REPRO-3** CI runs the same `make` targets as local.
- *Verified by:* CI, QG-CLEAN-CHECKOUT.

## NFR-DOC — Documentation

- **NFR-DOC-1** README explains the product, setup, the quality benchmark, and
  carries CI badges linking to public runs.
- **NFR-DOC-2** A user manual / docs page (FR-PUBLIC-4) covers setup and booking.
- **NFR-DOC-3** Architecture decisions recorded as ADRs in `docs/adr/`.
- **NFR-DOC-4** The final quality report (`docs/quality-report.md`) is honest and
  complete (Epic 10).
- *Verified by:* Product/Code Quality review, presence checks.

## NFR-OPS — Operational readiness

- **NFR-OPS-1** Health-check endpoint (FR-OPS-1) usable by a load balancer.
- **NFR-OPS-2** All long-running work is queued; the app does not block on email.
- **NFR-OPS-3** Config is environment-driven; no environment-specific code paths
  hard-coded.
- **NFR-OPS-4** Database migrations are forward-only and safe to run on deploy.
- *Verified by:* health-check test, queue usage review, Architecture review.

---

## NFR → gate map

| NFR area | Gate / proof |
|----------|--------------|
| Performance | QG-PERF + N+1 tests |
| Accessibility | QG-A11Y + Lighthouse a11y |
| Reliability | concurrency + idempotency tests |
| Observability | logging/correlation tests |
| Maintainability | QG-FORMAT/STATIC/COMPLEXITY/DUPLICATION/DEADCODE + arch tests |
| Reproducibility | QG-CLEAN-CHECKOUT + CI |
| Documentation | presence + review |
| Operational | health-check test + review |
