# Epic 10 — Hardening & quality report

## Goal

Close every outstanding review finding, get the full `make verify` pipeline
green, and produce the honest, public proof package the experiment requires.

## Requirements covered

Cross-cutting: the application Definition of Done in
[../definition-of-done.md](../definition-of-done.md) and the
[../quality-gates.md](../quality-gates.md) thresholds.

## In scope

- Resolve all non-blocking findings deferred during earlier epics (or record
  them as accepted risks with justification).
- Final accessibility and performance polish across all public + key app pages.
- Full `make verify` green locally and in CI on the default branch.
- **Final quality report** `docs/quality-report.md` containing:
  implemented epics, skipped/reduced scope, assumptions, architecture summary,
  test summary, tool results, known limitations, security notes, performance
  notes, accessibility notes, remaining risks, recommended next steps.
- **Scorecard** rating each category (functional completeness, test quality,
  code quality, architecture, security, UX/accessibility, performance/
  reliability, documentation/reproducibility) on the levels: failed, prototype,
  solid MVP, production-quality, exceptional.
- README with CI badges linking to public runs, and a "Reproduce the benchmark"
  section (`make setup && make verify`).
- Proof package assembled per [../proof-package.md](../proof-package.md).

## Out of scope

New features. Infrastructure/deployment (out of experiment scope per the brief).

## Acceptance criteria

- **AC-1** `make verify` is green end to end on a clean checkout and in CI.
- **AC-2** Every gate in [../quality-gates.md](../quality-gates.md) meets its
  threshold, or a deviation is explicitly justified in the quality report.
- **AC-3** All six structured reviews return **pass** (or pass-with-warnings with
  no blocking findings) on the final state.
- **AC-4** `docs/quality-report.md` and the scorecard exist, are honest (state
  what could not be checked), and link to evidence.
- **AC-5** README badges link to real, public CI runs and reports.

## Implementation notes

- The report must be honest: if a gate was reduced, sampled, or skipped, say so
  and why. Overclaiming fails the spirit of the experiment.
- Prefer fixing the underlying cause over suppressing a gate; any suppression
  must be justified inline and in the report.

## Required tests

- The full suite plus all gates; no new feature tests expected beyond closing
  coverage/mutation gaps surfaced by reviews.

## Done when

Application Definition of Done met; proof package complete; quality report and
scorecard published.
