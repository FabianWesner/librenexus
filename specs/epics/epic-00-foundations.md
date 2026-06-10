# Epic 00 — Foundations & quality harness

## Goal

Stand up the full quality pipeline and wire `make verify` so every later epic
inherits a green baseline. **Important nuance:** the *static and security* gates
must be green from day one; the *progressive* gates (coverage, mutation, e2e,
a11y, performance) cannot be green on an empty skeleton and are instead **wired
and documented**, then driven to green by the epics that add the relevant code
and pages. See [../quality-gates.md](../quality-gates.md) §Baseline status.

## Requirements covered

FR-OPS-1, FR-OPS-2 (FR-OPS-3 seeded incrementally).

## In scope

- Confirm the toolchain installed in `specs/` preparation works end to end:
  Pint, PHPMD, PHPStan/Larastan, jscpd, composer-unused, composer-require-checker,
  Pest (+ coverage via pcov, mutation via `--mutate`), Pest browser (Playwright),
  gitleaks, semgrep, `composer audit`, `npm audit`, osv-scanner, pa11y, Lighthouse
  CI, syft.
- `make verify` target wired and documented; CI (`.github/workflows/ci.yml`)
  mirrors it.
- Health-check route (`/health`) returning app + DB status as JSON.
- Structured JSON logging with a per-request correlation ID middleware.
- **Observability baseline (NFR-OBS):** an error-tracking integration point
  (configurable DSN, no-op when unset) and failed-job visibility (a recorded,
  inspectable failed-jobs store). Owned here so later epics just use it.
- **Security headers (SEC-HEADERS):** global middleware setting nosniff,
  referrer-policy, frame protection, a Blade/Livewire-appropriate CSP, and
  secure/http-only/same-site cookies. Owned here so every page inherits them.
- ADR log directory `docs/adr/` with ADR-0001 recording the stack decision.
- Static + security gates green; progressive gates (coverage, mutation, e2e,
  a11y, performance) wired with a written baseline note in
  [../quality-gates.md](../quality-gates.md) §Baseline status.

## Out of scope

Any LibreNexus domain feature.

## Acceptance criteria

- **AC-1** `make setup` then the **static + security** gates
  (`make format-check complexity static duplication unused require-check
  security`) succeed from a fresh clone with PostgreSQL available; `make verify`
  runs end to end with only the progressive gates falling short of threshold,
  each accounted for in the baseline-status table. Documented in README.
- **AC-2** CI runs the same gates on the default branch; the static + security
  jobs are green. The progressive thresholds are reached as their epics land
  (tracked, not silently lowered).
- **AC-3** `GET /health` returns 200 with `{status, database, time}` and 503 if
  the DB is unreachable.
- **AC-4** Every log line in production format is JSON and includes a
  `correlation_id`; the ID is generated per request and returned in a response
  header.
- **AC-5** Coverage and mutation thresholds in the Makefile match
  [../quality-gates.md](../quality-gates.md).
- **AC-6** Security headers (SEC-HEADERS) are present on responses (asserted by a
  test); the error-tracking DSN and failed-job store are configured and inert
  when unset.

## Implementation notes

- Reuse the existing tool configs (`phpstan.neon`, `phpmd.xml`, `.jscpd.json`,
  `lighthouserc.json`, `.pa11yci`, `.gitleaks.toml`, `composer-unused.php`,
  `composer-require-checker.json`). Do not weaken thresholds to pass; fix code.
- Correlation ID: middleware that reads `X-Request-Id` or generates a UUID,
  binds it to the logger context, and echoes it back.
- Health check must not require auth and must be excluded from rate limiting.

## Required tests

- Feature test for `/health` (200 happy path; 503 when DB down, simulated).
- Test asserting the correlation-ID header is present and stable within a
  request.
- Test asserting SEC-HEADERS response headers and cookie flags are set.
- Arch test (Pest `arch()`) establishing baseline boundaries (see
  [../architecture.md](../architecture.md)).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md) **and** `make verify`
is green locally and in CI.
