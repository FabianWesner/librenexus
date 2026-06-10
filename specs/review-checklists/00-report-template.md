# Review Report — <Role> — Epic <NN> (<epic name>)

> Copy this template to `docs/reviews/epic-NN/<role>.md`. Fill every section.
> A review with empty evidence is invalid.

## Reviewed scope

- **Epic / change:** <epic NN / commit range>
- **Requirements/rules in scope:** <FR-* / SEC-* / ARCH-* / QG-* / NFR-* IDs>

## Files reviewed

- <path> — <why>
- ...

## Flows reviewed

- <user flow / endpoint / job> — <what was checked>
- ...

## Tests reviewed

- <test file::test name> — <what it proves>
- ...

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make <target>` | pass/fail | <key numbers, e.g. coverage %, findings count> |

## Checklist results

> Use the role's checklist. Mark each item ✅ pass / ⚠️ warning / ❌ fail / n/a,
> with evidence (file:line, test, or tool output).

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | <checklist item> | ✅/⚠️/❌/n/a | <proof> |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Critical/High/Medium/Low | <ID> | <file:line / flow> | <what is wrong> | <what to do> |

## Required fixes (blocking)

- <list Critical/High findings that block done; empty if none>

## Final decision

**PASS** / **PASS WITH WARNINGS** / **FAIL**

- Rationale: <one or two sentences tied to findings>
- Blocking findings remaining: <count> (must be 0 to mark the epic done)
