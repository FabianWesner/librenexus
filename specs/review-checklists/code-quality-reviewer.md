# Code Quality Reviewer — Checklist

**Verifies against:** [../quality-gates.md](../quality-gates.md) (code-quality
gates), [../non-functional.md](../non-functional.md) (NFR-MAINT), project coding
guardrails (CLAUDE.md).

**Mission:** confirm the code is clean, consistent, idiomatic, and free of the
smells the gates target — and that no gate was gamed.

## Checklist

1. **Format** — `make format-check` passes (QG-FORMAT); no hand-formatting around
   Pint.
2. **Static** — `make static` passes at level 7 with **no baseline** and no
   unjustified ignores (QG-STATIC, QG-NO-IGNORE).
3. **Complexity** — `make complexity` passes; methods within cyclomatic/length/
   parameter limits (QG-COMPLEXITY); large functions refactored, not suppressed.
4. **Dead code** — `make complexity` unusedcode clean; no unused private
   members/vars (QG-DEADCODE).
5. **Duplication** — `make duplication` under 3% (QG-DUPLICATION); real
   duplication extracted, not hidden by widening ignores.
6. **Dependencies** — `make unused` and `make require-check` pass; filters carry
   reasons (QG-DEPS-UNUSED, QG-DEPS-IMPLICIT).
7. **Idioms** — PHP 8 constructor promotion, explicit return types and param type
   hints, TitleCase enum keys, descriptive names (`isRegisteredForDiscounts`,
   not `discount()`); PHPDoc with array shapes where useful.
8. **Laravel way** — `make:` generators used, Eloquent/relationships idiomatic,
   `route()`/named routes, config over magic values.
9. **Reuse** — existing components/helpers reused before new ones written.
10. **No debug/leftovers** — no `dd`/`dump`/`ray`/`var_dump`; no commented-out
    code blocks; no untracked `TODO`/`FIXME` (QG-NO-TODO).
11. **Consistency** — new files match sibling files' structure/naming/approach.
12. **Docs** — non-obvious decisions captured (ADR/comment for complex logic
    only); public docs updated where user-facing behavior changed.

## Tools to run

`make format-check`, `make static`, `make complexity`, `make duplication`,
`make unused`, `make require-check`.

## Decision rule

- **Fail** for any failing code-quality gate, a baseline/ignore added to dodge a
  gate, debug code, or duplication hidden by config.
- **Pass with warnings** for Medium style/consistency nits that are tracked.
- **Pass** when all code-quality gates are green and the code reads like the
  surrounding code.
