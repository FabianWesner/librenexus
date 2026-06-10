# Product Reviewer — Checklist

**Verifies against:** [../requirements.md](../requirements.md),
[../pages.md](../pages.md), the epic's acceptance criteria.

**Mission:** confirm the increment does what it must, completely, with the right
UX — judged against explicit FR/AC, not taste.

## Checklist

1. **AC coverage** — every acceptance criterion in the epic file is implemented
   and demonstrable (cite the flow/test that shows it).
2. **MUST requirements** — every in-scope `FR-*` MUST is satisfied; SHOULDs are
   met or have a documented assumption.
3. **Pages present** — every page for this epic in [../pages.md](../pages.md)
   exists at its route, renders 200, and matches its described elements.
4. **Happy path works** — the primary user journey can be completed end to end
   (verified via a browser/feature test or manual evidence).
5. **Validation & errors** — invalid input produces clear, actionable messages;
   nothing silently fails.
6. **Empty / loading / error states** — lists and dashboards have helpful empty
   states; async actions give feedback (styleguide).
7. **Copy** — UI text is clear, action-oriented, consistent with the glossary,
   and free of em-dashes.
8. **Navigation & links** — links resolve (no 404); footer present on public
   pages; tenant-scoped links carry the slug.
9. **Scope discipline** — no out-of-scope features sneaked in; any reduced scope
   is recorded as an assumption.
10. **Onboarding / discoverability** (where relevant) — a new user/tenant can
    find the next step (FR-DASH-2).

## Decision rule

- **Fail** if any in-scope MUST/AC is unmet or a primary flow is broken.
- **Pass with warnings** only for Medium/Low UX gaps that are tracked.
- **Pass** when all in-scope MUSTs/ACs are met with evidence.
