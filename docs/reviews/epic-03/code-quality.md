# Review Report - Code Quality - Epic 03 (Tenancy & isolation)

## Reviewed scope

- **Epic / change:** Epic 03 (Tenancy & isolation), current working tree
- **Requirements/rules in scope:** QG-FORMAT, QG-STATIC, QG-COMPLEXITY, QG-DEADCODE, QG-DUPLICATION, QG-DEPS-UNUSED, QG-DEPS-IMPLICIT, QG-NO-TODO, NFR-MAINT, CLAUDE.md guardrails

## Files reviewed

- `app/Data/{CurrentTenant,TeamPermissions,UserTeam}.php`, `app/Models/Scopes/TenantScope.php`, `app/Concerns/{BelongsToTenant,HasTeams,GeneratesUniqueTeamSlugs}.php`, `app/Models/{Team,Membership,TeamInvitation,TenantModel}.php`
- `app/Enums/{TeamRole,TeamPermission}.php`, `app/Policies/TeamPolicy.php`, `app/Http/Middleware/EnsureTeamMembership.php`
- `app/Actions/Teams/{CreateTeam,TransferTeamOwnership,DeleteUserWithTenants}.php`, `app/Rules/{TeamName,TeamSlug,UniqueTeamInvitation}.php`
- `app/Notifications/Teams/TeamInvitation.php`, `app/Providers/{AppServiceProvider,FortifyServiceProvider}.php`
- `resources/views/pages/teams/⚡*.blade.php`, `resources/views/components/⚡{team-switcher,create-team-modal}.blade.php`
- `database/migrations/2026_01_27_*`, `2026_06_10_204507_*`
- Configs gating the tools: `pint.json`, `phpstan.neon` (level 7, no baseline, no ignores), `phpmd.xml`, `.jscpd.json` (threshold 3, migrations/flux excluded with reason), `composer-unused.php`, `composer-require-checker.json`

## Flows reviewed

- Gate-gaming check: phpstan.neon has no baseline and no `ignoreErrors`; .jscpd.json ignores were not widened this epic; no `@phpstan-ignore` / `nosemgrep` markers in epic files (grep clean)
- Consistency check: new Livewire pages mirror the existing `pages::settings.*` single-file-component structure; modals follow the shared `flux:modal` + `Gate::authorize` + toast + redirect pattern

## Tests reviewed

- `tests/Unit/TeamRoleTest.php` - enum behavior pinned (labels, permission sets, hierarchy, assignable)
- `tests/Unit/TenantScopingTest.php` + `tests/Unit/ArchTest.php` - conventions enforced programmatically (trait opt-in, no debug helpers, enum/model/middleware rules)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `vendor/bin/pint --parallel --test` | pass | 0 diffs - fresh run (QG-FORMAT) |
| `vendor/bin/phpstan analyse` | pass | Level 7, 0 errors, no baseline - fresh run (QG-STATIC) |
| `make complexity` (PHPMD) | pass | 0 findings incl. unusedcode rules - fresh run (QG-COMPLEXITY/QG-DEADCODE) |
| `make duplication` (jscpd) | pass | 1.79% < 3% threshold - fresh run (QG-DUPLICATION) |
| `make unused` | pass | 0 unused deps (livewire/flux filter carries a reason) - fresh run |
| `make require-check` | pass | no unknown symbols - fresh run |
| grep `TODO/FIXME/dd(/dump(/ray(` | pass | 0 hits in app/ and resources/views (QG-NO-TODO) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Format | ✅ | Pint clean, fresh run |
| 2 | Static | ✅ | L7, zero errors, zero ignores; generics annotated (`@implements Scope<Model>`, `BelongsTo<Team, $this>`) |
| 3 | Complexity | ✅ | PHPMD clean; longest new method (`populateTeamData`) is linear data shaping within limits |
| 4 | Dead code | ✅ | PHPMD unusedcode clean; note `CurrentTenant::get()` currently has no production caller but is the natural read API alongside `id()` |
| 5 | Duplication | ✅ | 1.79%; the modal trio (remove/transfer/cancel) shares shape but differs in behavior - below threshold, not config-hidden |
| 6 | Dependencies | ✅ | unused + require-check clean, filters documented |
| 7 | Idioms | ⚠️ | Constructor promotion (`UniqueTeamInvitation`, notification), explicit return/param types throughout, TitleCase enum keys, descriptive names (`isLastOwner`, `ensureUserIsNotSoleOwner`); gaps: `Team::owner(): ?Model` should be `?User` (F2); several public Livewire `array` props lack array-shape PHPDoc (`$members`, `$invitations`, `$teamData`) (F3) |
| 8 | Laravel way | ✅ | Global scope via `Scope` contract + `addGlobalScope`, route-key binding on slug/code, named routes + `route()`, casts methods, factories with states, scheduled command for pruning |
| 9 | Reuse | ✅ | Reuses `GeneratesUniqueTeamSlugs`, settings layout, Flux components; `TeamSlug extends TeamName` keeps the reserved list single-source |
| 10 | No debug/leftovers | ✅ | grep clean; arch test enforces it permanently |
| 11 | Consistency | ⚠️ | Matches sibling structure; one inconsistency: `⚡edit.blade.php` shapes members/invitations as raw arrays while the switcher/index use the `UserTeam` DTO pattern (F3); the edit component is also the largest in the codebase (F1) |
| 12 | Docs | ✅ | ADR-0002 written; assumptions §Tenancy records co-owners, slug stability, currency list, allowlist rationale; non-trivial code carries intent comments (fail-closed scope, slug stability, 404 choice) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-MAINT / ARCH-STRUCTURE-2 | `resources/views/pages/teams/⚡edit.blade.php` | ~230 lines of component PHP combining three persistence flows, data shaping, and the last-owner invariant; siblings keep domain rules in Actions (TransferTeamOwnership). Works and is tested, but it is the file future epics will keep growing | Extract an `UpdateTeamMemberRole` action (carrying the last-owner guard) and consider DTOs for the view data; track for the next touch |
| F2 | Low | idioms | `app/Models/Team.php` (`owner(): ?Model`) | Weak return type loses User type information downstream | Type as `?User` |
| F3 | Low | idioms / consistency | `⚡edit.blade.php` public props | `$members`, `$invitations`, `$teamData` are untyped arrays without array-shape PHPDoc, while equivalent data elsewhere uses the `UserTeam` readonly DTO | Add array shapes or reuse the DTO pattern |
| F4 | Low | consistency | `resources/views/pages/teams/⚡index.blade.php` | Mixed modal-trigger mechanisms (`flux:modal.trigger` plus a manual Alpine `$dispatch('open-modal', ...)` on the same button) | Drop the redundant Alpine handler |

## Required fixes (blocking)

- None.

## Initial decision (2026-06-10, first pass)

**PASS WITH WARNINGS**

- Rationale: every code-quality gate is green on fresh runs with no baselines, ignores, or threshold games; the code is idiomatic Laravel and consistent with its siblings. Warnings are one Medium maintainability item (the oversized teams edit component) and three Low nits, tracked for the next touch of these files.
- Blocking findings remaining: 0

## Re-review after fixes (2026-06-10)

Re-read the fix-round code and re-ran every gate fresh:

- New/changed code holds the bar: the `BelongsToTenant::creating` branches carry intent comments tied to rule IDs (SEC-TENANT-2, trusted-path rationale); `TeamName::isReserved()` is a small public static accessor that keeps the reserved list single-source instead of duplicating it in the slug concern; the `->except(TeamRole::Owner)` validation carries a comment naming the FR; new tests follow the existing naming/dataset conventions. Bonus cleanup: `TeamPermissions` now takes the role in its constructor (named-arg boilerplate in `HasTeams::toTeamPermissions` removed).
- One note: the `while (TeamName::isReserved($candidate))` guard in `GeneratesUniqueTeamSlugs` is partially uncovered (88%, lines 25/38/50) - the double-reserved fallthrough is a near-unreachable belt-and-braces branch; acceptable, not designated critical.
- Fresh runs: Pint 0 diffs; PHPStan L7 0 errors, still no baseline/ignores; PHPMD 0 findings; jscpd 1.96% < 3% (slightly up from 1.79% due to structurally similar new tests, well under threshold and not config-hidden); composer-unused and require-checker clean; no TODO/debug markers introduced.
- F1 (edit-component extraction), F2 (`Team::owner()` return type), F3 (array-shape PHPDoc), F4 (redundant Alpine trigger) remain open Medium/Low items, tracked.

## Final decision

**PASS WITH WARNINGS**

- Rationale: unchanged from the first pass - all gates green on fresh post-fix runs, and the fix-round code matches the surrounding quality; the tracked Medium/Low nits remain for the next touch.
- Blocking findings remaining: 0
