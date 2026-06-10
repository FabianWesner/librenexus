# Epic 01 — Public marketing & legal site

## Goal

Ship the public-facing site that makes LibreNexus look like a real product:
marketing homepage, pricing, documentation, open-source/proof page, and legal
pages, all linked from a global footer.

## Requirements covered

FR-PUBLIC-1 … FR-PUBLIC-7.

## In scope

- Marketing homepage with hero, value proposition, feature highlights, and a
  primary CTA (sign up). The secondary "see a demo booking page" CTA is built
  here but **links to the demo tenant's booking URL**, which only goes live once
  Epic 06 (+ Epic 09 seeder) ships. Until then it is feature-flagged/hidden or
  points to a "coming soon" anchor — never a dead link (AC-2). It is switched on
  and verified in Epic 09/Epic 10.
- Pricing page stating the product is fully free and MIT-licensed.
- Documentation / user manual page (setup walkthrough + how booking works). Docs
  are seeded here and **finalized in Epic 10** once all flows exist.
- Open-source page: GitHub repo link, MIT license, and CI/badge links — these
  exist from Epic 00 and are added now. Links to the **final quality report and
  coverage/mutation/security/SBOM artifacts** are placeholders here and
  **finalized in Epic 10** when those artifacts are published; placeholders must
  resolve (e.g. to the CI page), never 404.
- Privacy policy and imprint pages with placeholder-but-plausible content.
- Global footer (pricing, docs, open-source, privacy, imprint, repo) on every
  public page.
- `LICENSE` file (MIT) at repo root.

## Out of scope

Authenticated app screens (Epic 02+). Real legal text from a lawyer (placeholder
content clearly marked).

## Acceptance criteria

- **AC-1** All pages in [../pages.md](../pages.md) §Public render at their routes
  with 200 and match the styleguide.
- **AC-2** The footer appears on every public page and every link resolves
  (no 404).
- **AC-3** Homepage and pricing pass QG-A11Y (WCAG2AA, zero errors) and QG-PERF
  (Lighthouse budgets in `lighthouserc.json`).
- **AC-4** `LICENSE` exists and is MIT; the open-source page links to it.
- **AC-5** Pages are responsive (mobile, tablet, desktop) per the styleguide
  breakpoints.

## Implementation notes

- Server-rendered Blade + Flux UI + Tailwind 4 per [../styleguide.md](../styleguide.md).
- Keep these pages public, cacheable, and free of N+1 or heavy queries to meet
  the performance budget.
- Add each new public URL to the `PUBLIC_URLS` list used by `make accessibility`
  and `make performance`.

## Required tests

- Smoke tests (Pest browser or HTTP) for every public route returning 200 with
  no console/JS errors.
- A test asserting footer links resolve.
- a11y and performance gates extended to cover homepage + pricing.

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); QG-A11Y and QG-PERF
green for all public pages added.
