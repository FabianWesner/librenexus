# QA Reviewer — Checklist

**Verifies against:** [../test-plan.md](../test-plan.md),
[../quality-gates.md](../quality-gates.md) (test gates).

**Mission:** confirm the tests genuinely prove the behavior — meaningful, at the
right layer, covering edge cases, and surviving mutation.

## Checklist

1. **Tests exist** — every "Required test" for the epic is present and passing
   (`make test`).
2. **Right layer** — pure logic is unit-tested; flows are feature-tested; key
   journeys have a browser/E2E test (no console errors).
3. **Coverage** — `make coverage` meets ≥ 80% overall and ≥ 95% for critical
   domain logic touched by this epic; cite the numbers.
4. **Mutation** — `make mutation` meets ≥ 70% (≥ 85% critical) for classes with
   `covers()`/`mutates()`; weak assertions exposed by surviving mutants are
   fixed.
5. **Meaningful assertions** — tests assert outcomes, not just execution; no
   assertion-free "coverage padding".
6. **Edge cases** — the test-plan edge cases relevant to this epic are present
   (DST, midnight, buffers, lead time/horizon, cancel cut-off, just-taken slot,
   cross-tenant IDs, forged tokens).
7. **Named suites** — tenancy isolation and booking concurrency suites are green
   and not weakened.
8. **Factories & data** — models built via factories/states; `fake()` for data;
   `RefreshDatabase` on PostgreSQL.
9. **Async assertions** — `Mail`/`Queue`/`Notification` fakes assert jobs are
   queued (not inline) with correct contents.
10. **No skips** — no `skip`/`only`/incomplete tests left in; any skip is
    justified and tracked.
11. **Determinism** — tests are not flaky/time-dependent; slot tests run under a
    non-UTC app timezone too.

## Tools to run

`make test`, `make coverage`, `make mutation`, `make e2e` (where pages exist).

## Decision rule

- **Fail** if a required test is missing, a gate threshold is unmet, a named
  suite is weakened, or coverage is padded (mutation reveals it).
- **Pass with warnings** for Medium gaps in non-critical areas, tracked for
  Epic 10.
- **Pass** when test gates are green with meaningful coverage.
