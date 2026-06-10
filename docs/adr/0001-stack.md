# ADR-0001: Technology stack

- Status: accepted
- Date: 2026-06-10

## Context

LibreNexus is a workflow-heavy, multi-tenant scheduling app that must be built
quickly, be measurably testable, and pass a predefined quality benchmark
(specs/quality-gates.md). The experiment brief (specs/raw.md) fixes the stack;
this ADR records it and the rationale.

## Decision

- PHP 8.4 + Laravel 13 monolith, server-rendered.
- PostgreSQL as the only database. Required for production-like concurrency
  and for the range exclusion constraint that makes double-booking impossible
  at the database level (ARCH-DATA-3, see ADR-0003).
- Blade + Livewire 4 (SFC pages under `resources/views/pages`) + Flux UI +
  Tailwind 4 for the frontend; Alpine for small client interactions. No SPA.
- Fortify for authentication (incl. 2FA and passkeys from the starter kit).
- Database-backed queues for async work (emails, reminders).
- Pest 4 (+ PHPUnit) for unit/feature tests, Pest browser (Playwright) for
  E2E, pcov for coverage, Pest `--mutate` for mutation testing.
- GitHub Actions CI running the same `make` targets as local.

## Consequences

- Low architectural noise: one deployable, one database, server-rendered UI.
- All concurrency guarantees can be enforced in PostgreSQL itself.
- Quality is measured by the toolchain wired in the Makefile; no stack piece
  may be swapped or added without a new ADR (project guardrail).
