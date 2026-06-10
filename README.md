# LibreNexus

LibreNexus is a free, MIT-licensed, multi-tenant appointment scheduling system
for small offices (clinics, salons, studios, advisors). Each tenant has staff,
services, availability, a public booking page, and appointments booked by
customers, with email confirmations, reminders, and customer self-service.

This repository is also a **verified-app experiment**: the application is built
against the predefined, measurable quality benchmark in [`specs/`](specs/). The
claim is not "this repo is perfect" but "this repo passed a predefined, public,
reproducible quality benchmark."

## Setup

Requirements: PHP 8.4, Composer, Node 22, PostgreSQL (databases `librenexus`
and `librenexus_test`), plus the security toolchain (`gitleaks`, `semgrep`,
`osv-scanner`, `syft`) for the full pipeline.

```bash
make setup    # install deps, env, migrate, build assets, install browsers
```

## Reproduce the benchmark

```bash
make verify   # the full quality pipeline (the public benchmark)
```

`make verify` chains every gate: format, complexity/dead code, static analysis
(PHPStan level 7), duplication, unused/implicit dependencies, tests, coverage
(>= 80%), mutation (>= 70%), browser E2E, secrets, SAST, dependency audits,
accessibility (WCAG 2.1 AA), performance (Lighthouse budgets), and the SBOM.
`make help` lists each target. CI (`.github/workflows/ci.yml`) runs the same
targets on every push.

Gate definitions and thresholds: [`specs/quality-gates.md`](specs/quality-gates.md).
Architecture decisions: [`docs/adr/`](docs/adr/). Assumptions:
[`docs/assumptions.md`](docs/assumptions.md).

## License

[MIT](LICENSE)
