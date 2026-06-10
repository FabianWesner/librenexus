# LibreNexus — Definition of Done

Two levels of "done": **per epic** (every vertical slice) and **whole
application** (the experiment's final bar). Nothing is "done" by assertion — it
is done when the listed checks pass.

## Epic Definition of Done

An epic is done only when **all** of the following hold:

1. **Acceptance criteria** — every AC in the epic file is implemented and
   demonstrably met.
2. **Tests** — the epic's required tests exist and pass; new code is covered to
   the [test-plan.md](test-plan.md) targets (elevated for critical logic).
3. **Format** — `make format-check` passes (QG-FORMAT).
4. **Static** — `make static` passes, no baseline, no unjustified ignores
   (QG-STATIC).
5. **Complexity & dead code** — `make complexity` passes (QG-COMPLEXITY,
   QG-DEADCODE).
6. **Duplication** — `make duplication` under threshold (QG-DUPLICATION).
7. **Dependencies** — `make unused` and `make require-check` pass
   (QG-DEPS-UNUSED, QG-DEPS-IMPLICIT).
8. **Tests gate** — `make test` green (QG-TESTS); coverage and mutation meet
   targets for the touched code (QG-COVERAGE, QG-MUTATION).
9. **Security** — `make security` passes (QG-SECRETS, QG-SAST, QG-DEPS-VULN); the
   epic's relevant SEC-* rules are satisfied.
10. **Accessibility & performance** — for epics that add/alter pages, QG-A11Y and
    QG-PERF pass for those pages **via the correct mechanism** (test-plan.md
    §Accessibility & performance per page): public pages added to `PUBLIC_URLS`
    for pa11y/Lighthouse; authenticated/tokened pages checked with axe in the
    E2E suite and N+1/query-count tests. Stale gate lists are a blocking finding.
11. **Reviews** — the six structured reviews
    ([review-checklists/](review-checklists/)) are run; **no blocking
    (high/critical) findings remain**. Pass or pass-with-warnings only.
12. **Documentation** — relevant docs/ADRs updated; no untracked `TODO`/`FIXME`
    (QG-NO-TODO).
13. **App still works** — the application runs and the previously-green gates
    remain green (no regressions); the named regression suites (tenancy
    isolation, booking concurrency) stay green.

Blocking findings (severity high/critical) **must** be fixed before the epic is
marked done. Lower-severity findings may be deferred with a tracked note and
addressed by Epic 10.

## Application Definition of Done

The whole application is done only when **all** of the following hold:

1. **Functional completeness** — every MUST requirement in
   [requirements.md](requirements.md) is implemented and tested; any reduced
   scope is documented as an assumption in the quality report.
2. **Full pipeline green** — `make verify` passes end to end on a clean checkout
   **and** in CI on the default branch (QG-CLEAN-CHECKOUT), including:
   format, complexity/dead code, static, duplication, unused/implicit deps,
   tests, coverage, mutation, e2e, secrets, SAST, dependency audit + OSV,
   accessibility, performance, SBOM.
3. **Thresholds met** — every gate in [quality-gates.md](quality-gates.md) meets
   its threshold, or each deviation is explicitly justified in the quality
   report (no silent reductions).
4. **Security rules satisfied** — all SEC-* rules in [security.md](security.md)
   hold; tenant isolation and token security proven by their named test suites.
5. **NFRs satisfied** — [non-functional.md](non-functional.md) requirements met
   or honestly noted.
6. **All six reviews pass** on the final state with no blocking findings.
7. **Proof package** — assembled per [proof-package.md](proof-package.md):
   quality report + scorecard (Epic 10), README with CI badges linking to public
   runs, reports, ADRs, MIT license.
8. **Honesty** — the quality report states anything that could not be checked,
   was sampled, or was reduced in scope.

## Severity definitions (for reviews)

| Severity | Meaning | Effect |
|----------|---------|--------|
| Critical | Security/data-integrity/tenant-isolation breach, or a failing gate | Blocks epic + app DoD; fix immediately. |
| High | Correctness bug in a MUST flow, or a missing required test | Blocks epic DoD; fix before proceeding. |
| Medium | Quality/maintainability issue, minor a11y/perf miss | Should fix; may defer to Epic 10 with a tracked note. |
| Low | Cosmetic / nice-to-have | Optional; record in the report. |
