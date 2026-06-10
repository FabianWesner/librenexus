# LibreNexus — Quality Gates

Quality gates are **measurable**. Each gate has a rule ID, a clear statement, a
programmatic tool (a `make` target wired into `make verify` and CI), a pass/fail
threshold, and a remediation requirement. A gate either passes or fails — review
opinion does not override a tool result.

`make verify` runs every gate from a clean checkout and is the single public
benchmark command. CI (`.github/workflows/ci.yml`) runs the same targets.

## How to read a gate

- **Tool / command** — the exact `make` target (and underlying tool/config).
- **Threshold** — the numeric or boolean bar; meeting it = pass.
- **Remediation** — what "fix it" means. Suppressions are a last resort and must
  be justified inline **and** in the final quality report; thresholds must not
  be weakened to pass.

## Global rules

- **QG-NO-IGNORE** No tool suppression (`@phpstan-ignore`, jscpd ignore, semgrep
  `nosemgrep`, baseline files) without an inline justification comment naming the
  reason. PHPStan baselines are **not allowed**; fix the type instead.
- **QG-NO-TODO** No `TODO`/`FIXME` in committed code unless it links to a tracked
  issue/epic ID. Checked in code review (Code Quality reviewer) and may be
  enforced with a grep step.
- **QG-CLEAN-CHECKOUT** `make setup && make verify` must succeed on a fresh clone
  with only PostgreSQL + Node + PHP 8.4 + the documented tools available.

---

## Code quality gates

| ID | Statement | Tool / command | Threshold | Remediation |
|----|-----------|----------------|-----------|-------------|
| **QG-FORMAT** | Code matches the project style. | `make format-check` (Pint, `pint.json`) | Exit 0, zero diffs | Run `make format`; never hand-format around Pint. |
| **QG-STATIC** | Static analysis is clean at level 7. | `make static` (PHPStan + Larastan, `phpstan.neon`, level 7) | 0 errors, **no baseline** | Fix the underlying type/logic; do not add baseline or inline ignores. |
| **QG-COMPLEXITY** | Methods/classes stay within complexity limits. | `make complexity` (PHPMD, `phpmd.xml`: cyclomatic ≤ 10, method ≤ 80 lines, params ≤ 8, etc.) | 0 violations | Refactor: extract methods, reduce branching, split classes. |
| **QG-DEADCODE** | No unused private code. | `make complexity` (PHPMD unusedcode ruleset) | 0 violations | Delete unused private fields/methods/vars or wire them in. |
| **QG-DUPLICATION** | Copy-paste stays below threshold. | `make duplication` (jscpd, `.jscpd.json`) | Duplicated lines **< 3%** | Extract shared code; do not widen `ignore` to hide real duplication. |
| **QG-DEPS-UNUSED** | No unused Composer dependencies. | `make unused` (composer-unused, `composer-unused.php`) | 0 unused | Remove the dependency or use it; document genuine false positives in the filter with a reason. |
| **QG-DEPS-IMPLICIT** | No reliance on transitive (implicit) deps. | `make require-check` (composer-require-checker) | 0 unknown symbols | Add the real dependency to `composer.json`. |

## Test gates

| ID | Statement | Tool / command | Threshold | Remediation |
|----|-----------|----------------|-----------|-------------|
| **QG-TESTS** | All unit + feature tests pass. | `make test` (Pest) | 100% pass, 0 risky-as-error | Fix code or test; no skipping without justification. |
| **QG-COVERAGE** | Line coverage meets target. | `make coverage` (Pest `--coverage --min=80`) | **≥ 80% overall**; **≥ 95%** for critical domain logic (slot engine, booking/concurrency, tenant scoping, cancellation tokens) | Add meaningful tests (not assertion-free); cover branches, not just lines. |
| **QG-MUTATION** | Tests detect injected faults. | `make mutation` (Pest `--mutate --min=70`) | **≥ 70%** overall; critical domain classes use `covers()`/`mutates()` and target **≥ 85%** | Strengthen weak assertions the mutation report exposes. |
| **QG-E2E** | Key user journeys work in a real browser. | `make e2e` (Pest 4 browser tests, Playwright) | All pass, no console/JS errors | Fix the flow or the test; required before app DoD. |

> Critical domain logic = the slot engine (Epic 05), booking concurrency
> (Epic 06), tenant scoping (Epic 03), and cancellation tokens (Epic 08). These
> carry elevated coverage and mutation targets per
> [test-plan.md](test-plan.md).

## Security gates

| ID | Statement | Tool / command | Threshold | Remediation |
|----|-----------|----------------|-----------|-------------|
| **QG-SECRETS** | No secrets committed. | `make secrets` (gitleaks, `.gitleaks.toml`) | 0 findings | Remove/rotate the secret; allowlist only documented non-secrets. |
| **QG-SAST** | No static security findings. | `make sast` (Semgrep `p/php` + `p/security-audit`) | 0 findings | Fix the vulnerability; `nosemgrep` only with written justification. |
| **QG-DEPS-VULN** | No known-vulnerable dependencies. | `make audit` (`composer audit`, `npm audit --audit-level=high`) + `make osv` (osv-scanner) | 0 high/critical advisories | Upgrade/replace the dependency; use `overrides`/constraints if transitive. |

> Detailed security **rules** (auth, authz, tenant isolation, input validation,
> headers, sessions, rate limiting, tokens) live in
> [security.md](security.md); the Security reviewer verifies them against
> SEC-* IDs.

## Non-functional gates

| ID | Statement | Tool / command | Threshold | Remediation |
|----|-----------|----------------|-----------|-------------|
| **QG-A11Y** | Public pages meet WCAG 2.1 AA (tool gate); authenticated/tokened pages meet it via in-browser axe checks. | **Public:** `make accessibility` (pa11y-ci, `.pa11yci`, `WCAG2AA`) over `PUBLIC_URLS`. **Authenticated/tokened:** Pest 4 browser `assertNoAccessibilityIssues()` (bundled axe-core) inside E2E tests that log in / use a token (part of `make e2e`). | 0 errors on each public URL **and** 0 serious/critical axe violations on the covered authenticated pages | Fix contrast, labels, landmarks, focus order. |
| **QG-PERF** | Public pages meet Lighthouse budgets; authenticated pages meet query-efficiency budgets. | **Public:** `make performance` (Lighthouse CI, `lighthouserc.json`) over `PUBLIC_URLS`. **Authenticated:** N+1 / query-count assertion tests (NFR-PERF-2) + server-render budget spot-check (NFR-PERF-1) in the Performance review. | Public: performance ≥ 0.90, accessibility ≥ 0.95, best-practices ≥ 0.90, SEO ≥ 0.90. Authenticated: no N+1 on list/calendar/dashboard; within the NFR-PERF-1 budget. | Reduce payload, fix render-blocking, add caching, eager-load. |

> **Why split?** pa11y-ci and Lighthouse run against URLs and cannot log in.
> `PUBLIC_URLS` therefore covers the marketing/legal pages, the public booking
> flow, and a **seeded demo manage-token URL** (so the tokened manage page is
> tool-checked too). Pages behind login (dashboard, lists, calendar, settings,
> tenant pages) are accessibility-checked with axe inside the browser E2E suite
> and performance-checked via N+1/query-count tests plus the Performance review.
> Each epic that adds/alters a page updates `PUBLIC_URLS` (public pages) or adds
> an axe assertion (authenticated pages) — see [test-plan.md](test-plan.md)
> §Accessibility & performance per page.

> Additional NFRs (API latency budget, observability, reliability, operational
> readiness) are defined and checked per [non-functional.md](non-functional.md).

## Supply-chain artifact

| ID | Statement | Tool / command | Output |
|----|-----------|----------------|--------|
| **SBOM** | A CycloneDX SBOM is generated. | `make sbom` (syft) | `reports/sbom.cdx.json` (artifact, published with the proof package) |

---

## Baseline status (starter-kit, before feature work)

Recorded honestly so progress is measurable. Run on the Laravel Livewire
starter kit baseline during `specs/` preparation:

| Gate | Baseline result | Notes |
|------|-----------------|-------|
| QG-FORMAT | pass | Pint clean. |
| QG-STATIC | pass | PHPStan level 7, 0 errors. |
| QG-COMPLEXITY / QG-DEADCODE | pass | After extracting the reserved-names list to a constant. |
| QG-DUPLICATION | pass | 1.96% < 3% (flux icon stubs + config excluded). |
| QG-DEPS-UNUSED | pass | `nesbot/carbon` + `symfony/http-foundation` made explicit; Flux/Blaze/Chisel filtered with reasons. |
| QG-DEPS-IMPLICIT | pass | 0 unknown symbols. |
| QG-TESTS | pass | 60/60 on PostgreSQL. |
| QG-COVERAGE | **76.8%** (below 80) | Starter-kit scaffolding is partly uncovered. This is the target the app must reach; **not** expected green on the bare skeleton. |
| QG-MUTATION | tooling verified | Requires `covers()`/`mutates()` in tests (a test-plan convention); 0 baseline classes covered yet. |
| QG-E2E | n/a | No browser tests yet; required before app DoD. |
| QG-SECRETS | pass | 0 findings (agent skill docs allowlisted). |
| QG-SAST | pass | 0 Semgrep findings. |
| QG-DEPS-VULN | pass | `composer audit`, `npm audit`, osv-scanner all clean. |
| QG-A11Y | tooling verified | Starter welcome page has a contrast issue; replaced by the real homepage in Epic 01. |
| QG-PERF | tooling verified | Needs the real public pages (Epic 01). |
| SBOM | pass | `reports/sbom.cdx.json` generated. |

The coverage/mutation/e2e/a11y/perf gates are intentionally **not** green on the
empty skeleton: driving them to green as the application is built is the point
of the experiment. `make verify` is the **final-app** benchmark.
