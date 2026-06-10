# LibreNexus — Specification & Benchmark

This directory is the **starting contract** for the Verified App experiment: it
defines what to build, how quality is protected, how quality is measured, and
how the agent should work — but contains no application code. The agent builds
everything else and must pass the predefined, measurable gates.

> **Experiment claim:** not "this repo is perfect", but **"this repo passed a
> predefined, public, reproducible quality benchmark."**

## The four separated concerns

| Concern | Defines | Files |
|---------|---------|-------|
| **Functional requirements** | What the app does | [requirements.md](requirements.md), [pages.md](pages.md) |
| **Guardrails** | How quality is protected | [architecture.md](architecture.md), [security.md](security.md), [non-functional.md](non-functional.md), [styleguide.md](styleguide.md) |
| **Quality gates** | How quality is measured | [quality-gates.md](quality-gates.md), [test-plan.md](test-plan.md), [definition-of-done.md](definition-of-done.md) |
| **The prompt** | How the agent works | [goal.prompt](goal.prompt) |

## Index

- **[goal.prompt](goal.prompt)** — the single one-shot build prompt (Claude Code
  goal mode). Start here to run the experiment.
- **[requirements.md](requirements.md)** — functional requirements (`FR-*`) +
  domain glossary + non-goals.
- **[roadmap.md](roadmap.md)** — phases and the ordered vertical-slice epics.
- **[epics/](epics/)** — one file per epic (00–10) with acceptance criteria.
- **[architecture.md](architecture.md)** — architecture rules (`ARCH-*`), stack,
  multi-tenancy, double-booking strategy.
- **[security.md](security.md)** — security rules (`SEC-*`), incl. critical
  tenant isolation.
- **[non-functional.md](non-functional.md)** — NFRs (`NFR-*`): performance,
  a11y, reliability, observability, reproducibility, ops.
- **[quality-gates.md](quality-gates.md)** — measurable gates (`QG-*`): rule,
  tool/`make` target, threshold, remediation, + honest baseline status.
- **[test-plan.md](test-plan.md)** — layers, coverage/mutation targets, named
  regression suites, edge cases.
- **[styleguide.md](styleguide.md)** — clean professional SaaS visual + UI rules.
- **[pages.md](pages.md)** — every page with a visual description.
- **[review-checklists/](review-checklists/)** — the six reviewer roles +
  structured report template.
- **[definition-of-done.md](definition-of-done.md)** — epic DoD + application DoD
  + severity definitions.
- **[proof-package.md](proof-package.md)** — what the final repo must publish.
- **[raw.md](raw.md)** — the original experiment brief (source of truth for
  intent).

## Run the benchmark

```bash
make setup    # install deps, env, migrate, build, browsers (needs PostgreSQL)
make verify   # the full quality pipeline — the public benchmark
```

`make help` lists every gate target. CI (`.github/workflows/ci.yml`) runs the
same targets on every push/PR.

## Tech stack (fixed)

PHP 8.4 · Laravel 13 · PostgreSQL · Blade + Livewire 4 + Flux UI · Tailwind 4 ·
Fortify · Pest + PHPUnit · Playwright (Pest 4 browser) · GitHub Actions.

## Toolchain installed (measurable evidence)

Pint (format) · PHPStan/Larastan (static, level 7) · PHPMD (complexity/dead
code) · jscpd (duplication) · composer-unused + composer-require-checker
(dependencies) · Pest coverage (pcov) + mutation (`--mutate`) · gitleaks
(secrets) · Semgrep (SAST) · composer audit + npm audit + osv-scanner
(dependency vulns) · pa11y-ci (accessibility) · Lighthouse CI (performance) ·
syft (SBOM).

See [quality-gates.md](quality-gates.md) for the mapping of each tool to its
gate, threshold, and `make` target.
