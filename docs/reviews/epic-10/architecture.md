# Review Report - Architecture Reviewer - Epic 10 (Hardening & quality report / final application state)

> Final-state review. The whole application is assessed; per-epic architecture
> reviews (epic-00..09, all pass) are relied on for unchanged areas, and the
> Epic 10 structural changes are re-verified directly.

## Reviewed scope

- **Epic / change:** Epic 10 hardening + final application state
- **Requirements/rules in scope:** ARCH-STRUCTURE-*, ARCH-TENANCY-*,
  ARCH-DATA-*, ARCH-HTTP-*, ARCH-ASYNC-*, ARCH-CONFIG-*, ARCH-FRONTEND-*,
  ARCH-TEST-3; NFR-MAINT/RELY

## Files reviewed

- `app/Actions/Teams/UpdateMemberRole.php` - Epic 03 deferral closed: member
  role logic extracted from the teams edit component into an Action
  (transaction + lockForUpdate + last-owner guard)
- `app/Providers/AppServiceProvider.php:66-71` + `config/auth.php:116` -
  password policy now config-driven (`auth.password_policy.strict`); null
  falls back to `isProduction()` (documented)
- `app/Http/Middleware/SetSecurityHeaders.php` - CSP decision re-verified in
  Epic 10 and documented inline (dropping `unsafe-eval` fails 27/35 browser
  tests)
- `resources/views/pages/appointments/⚡index.blade.php` - pagination via
  `WithPagination` + `paginate(self::PER_PAGE)` (lines 46, 364)
- `resources/views/pages/booking/⚡show.blade.php` - step throttling kept in
  one private helper (line 338), business logic still delegated to Actions
- `docs/adr/0001-stack.md`, `0002-tenant-scoping.md`,
  `0003-double-booking-constraint.md` - the three load-bearing ADRs
- `app/Providers/FortifyServiceProvider.php` - working-tree fix registering
  `twoFactorChallengeView` (absent at HEAD; see cross-cutting finding)

## Flows reviewed

- Tenant scoping under concurrency - isolation + concurrency suites re-run by
  this review (40 tests, 91 assertions, pass)
- Member role change - now routed through the `UpdateMemberRole` action with
  row locking; component is thin again
- Booking/reschedule write path - exclusion constraint remains the final
  arbiter (ADR-0003); re-validated inside the transaction

## Tests reviewed

- `tests/Unit/ArchTest.php`, `tests/Unit/TenantScopingTest.php` - arch rules +
  fail-closed scope behavior (part of the green 469-test run, verify.log 951)
- `tests/Feature/Tenancy/IsolationTest.php` - every tenant-owned model covered;
  re-run green
- `tests/Feature/Booking/ConcurrencyTest.php` - DB-level double-booking guard
  raced over two live connections; re-run green

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make static` | pass | PHPStan level 7, 0 errors, no baseline (re-run by this review) |
| `pest IsolationTest + ConcurrencyTest` | pass | 40 tests, 91 assertions |
| `/tmp/claude/verify.log` | pass | full pipeline green on the final tree |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Structure | ✅ | Epic 10 added only `app/Actions/Teams/UpdateMemberRole.php` (established directory); no new top-level folders |
| 2 | Logic placement | ⚠️ | Actions/services carry the domain; slot engine pure (ADR/Epic 05). Warning: several Livewire SFCs remain large (appointments index 780 lines incl. markup); split deferred and accepted, tracked in assumptions.md |
| 3 | Tenant scoping | ✅ | `BelongsToTenant` + arch-test enforcement; isolation suite re-run green |
| 4 | No leaky queries | ✅ | Per-epic reviews + `findByManageToken` exception documented (assumptions.md §Booking); no new queries in Epic 10 bypass the scope |
| 5 | Data | ✅ | Forward-only migrations, UTC storage pinned at the connection (config/database.php), integer minor units, no interpolated SQL (Semgrep clean) |
| 6 | Double-booking | ✅ | Partial GiST exclusion constraint, ADR-0003; concurrency suite green on re-run |
| 7 | HTTP | ✅ | Validation server-side, named routes, policies on actions (per-epic reviews; Epic 10 changed no route surface) |
| 8 | Async | ✅ | All four mailables queued with scalar capture; failed-job store from Epic 00 |
| 9 | Config/secrets | ⚠️ | Password policy now config-driven (deferral closed); residual `?? $this->app->isProduction()` fallback is environment-aware but documented and test-forced both ways. No secrets in code (gitleaks 0) |
| 10 | Frontend | ⚠️ | Server-rendered Livewire/Flux; CSP keeps `unsafe-inline`/`unsafe-eval`, re-verified and documented in Epic 10 (accepted stack trade-off) |
| 11 | Arch tests | ✅ | `ArchTest.php` green in the 469-test run |
| 12 | ADRs | ✅ | 0001 stack, 0002 tenant scoping, 0003 booking constraint present and accurate |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | ARCH-STRUCTURE-2 / NFR-MAINT | appointments/booking/teams SFCs (780/603/491 lines) | Largest page components were not split in Epic 10 as the Epic 07 deferral planned; accepted instead and documented in quality-report.md limitation 1. Markup dominates the line counts and PHPStan covers the front-matter, so risk is contained | Track as accepted; split front-matter into Actions if components grow again |
| F2 | Low | ARCH-CONFIG-2 | AppServiceProvider.php:71 | Strict password policy falls back to `isProduction()` when the config flag is null; a pure-config default would remove the last environment branch | Optional: default the flag in config and drop the fallback |
| F3 | High (cross-ref) | App DoD #2 | HEAD `fe103c7` vs working tree | At HEAD, `Fortify::twoFactorChallengeView` is unregistered, so the 2FA challenge route 500s (caught by CI). The fix is in the working tree, uncommitted. Owned by the Product report (its F1); listed here because a boot-time registration gap is an integration defect | Commit/push the working-tree fixes |

## Required fixes (blocking)

- None owned by this review (F3 is the shared publication blocker tracked as
  the Product report's F1).

## Final decision

**PASS WITH WARNINGS**

- Rationale: boundaries hold across the final state: fail-closed tenant
  scoping, DB-level booking integrity, a pure slot engine, queued async, and
  all three ADRs implemented as written. Warnings are the accepted SFC sizes,
  the CSP trade-off, and the residual environment fallback in the password
  policy, all documented. The unpublished Fortify-registration fix is blocking
  at the application level but is tracked by the Product review.
- Blocking findings remaining: 0 owned here (1 shared, tracked as product F1)
