# LibreNexus — Public Proof Package

The experiment must be provable to outsiders. The claim is not "this repo is
perfect" but **"this repo passed a predefined, public, reproducible quality
benchmark."** This file lists exactly what the final repository must publish.

## Contents

- **Initial repository** — the starting point (this prepared `specs/` + tooling
  baseline), tagged so the before/after is visible.
- **Final repository** — the completed LibreNexus app.
- **The prompt** — [goal.prompt](goal.prompt) (the single build prompt).
- **Requirements & guardrails** — all of `specs/` (requirements, architecture,
  security, NFRs, quality gates, DoD, test plan, styleguide, pages, review
  checklists).
- **CI results** — public GitHub Actions runs of `.github/workflows/ci.yml`.
- **Test report** — Pest output / summary.
- **Coverage report** — line coverage (QG-COVERAGE).
- **Mutation report** — Pest `--mutate` score (QG-MUTATION).
- **Security reports** — gitleaks, Semgrep, `composer audit`/`npm audit`,
  osv-scanner outputs (`reports/`).
- **SBOM** — `reports/sbom.cdx.json` (CycloneDX, syft).
- **Review reports** — the six structured reviews per epic + final
  (`docs/reviews/`).
- **Architecture decisions** — `docs/adr/`.
- **Final quality report** — `docs/quality-report.md` (Epic 10) incl. the
  scorecard.
- **Demo video** — short walkthrough (recorded outside the experiment).
- **Public website / docs** — the app's own public pages (FR-PUBLIC).
- **Repository link** — GitHub URL on the open-source page + README.
- **MIT license** — `LICENSE` at repo root.

## README requirements

- Project intro + screenshot.
- CI **badges** for the pipeline; **every badge links to a public run**.
- "Reproduce the benchmark" section: `make setup && make verify`.
- Links to the quality report, coverage, mutation, security reports, SBOM.
- License + repository links.

## Scorecard (in the quality report)

Rate each category at one level: **failed / prototype / solid MVP /
production-quality / exceptional**.

| Category | Level | Evidence |
|----------|-------|----------|
| Functional completeness | | FR coverage, e2e |
| Test quality | | coverage + mutation |
| Code quality | | format/static/complexity/duplication |
| Architecture | | arch tests, ADRs |
| Security | | SEC-* + security gates |
| UX & accessibility | | QG-A11Y, reviews |
| Performance & reliability | | QG-PERF, concurrency |
| Documentation & reproducibility | | README, docs, QG-CLEAN-CHECKOUT |

## Honesty clause

The quality report must state anything that could not be checked, was sampled,
or was reduced in scope, and list remaining risks and recommended next steps.
Overclaiming defeats the purpose of the benchmark.
