# Review Report - Performance - Epic 03 (Tenancy & isolation)

## Reviewed scope

- **Epic / change:** Epic 03 (Tenancy & isolation), current working tree
- **Requirements/rules in scope:** NFR-PERF-1/2, NFR-RELY-1/2, NFR-OPS-2, NFR-OBS, QG-PERF (no public pages added this epic)

## Files reviewed

- `resources/views/pages/teams/⚡edit.blade.php` - members/invitations loading for the settings page
- `app/Concerns/HasTeams.php` - `toUserTeams()` / `toUserTeam()` / `teamRole()` query patterns (switcher, teams index, delete-team fallback)
- `resources/views/components/⚡team-switcher.blade.php`, `resources/views/pages/teams/⚡index.blade.php`, `⚡pending-invitations-modal.blade.php`
- `app/Models/Scopes/TenantScope.php` - per-query overhead (single indexed `where team_id`)
- `app/Notifications/Teams/TeamInvitation.php` - queued delivery
- `app/Actions/Teams/{CreateTeam,TransferTeamOwnership,DeleteUserWithTenants}.php` - transactional writes
- `database/migrations/2026_01_27_000001_create_teams_table.php` - indexes: unique slug (route lookup), unique (team_id, user_id) (membership checks), unique code (invitation lookup); FK columns indexed via `constrained()`
- `app/Console` scheduled prune of expired invitations
- `app/Rules/TeamName.php` - `once()` memoization of the route-derived reserved list

## Flows reviewed

- Tenant settings page load: members via one `members()->get()` (pivot role included, no per-member query), invitations via one query - no N+1
- Pending-invitations modal: `with(['inviter', 'team'])` eager-loads relations - no N+1
- Team switcher / teams index: `toUserTeams()` runs `teams()->get()` then one `teamRole()` query per team (F1)
- Invitation email: `ShouldQueue`, so invite requests return without SMTP latency (NFR-OPS-2); reminder jobs are later epics
- Ownership transfer / team delete / account delete: single transactions, `lockForUpdate` on the contended membership row - atomic (NFR-RELY-1 for this epic's writes)
- Middleware cost per tenant request: slug lookup (unique index) + one membership exists + optional role fetch + possible `switchTeam` update - bounded, no heavy work

## Tests reviewed

- `tests/Feature/Tenancy/TeamMembershipMiddlewareTest.php` - context switching works; no timing assertions (none required this epic)
- `tests/Feature/Teams/TeamInvitationNotificationTest.php` - proves queued (not inline) delivery
- `tests/Feature/Teams/PruneExpiredTeamInvitationsTest.php` - scheduled cleanup keeps the invitations table from growing unbounded
- No query-count assertion test exists for the tenancy screens (F2)

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact` | pass | 193/193 incl. browser suite; full run 11.6s - fresh run |
| `make performance` | not rerun | No public page added or changed this epic; Lighthouse budgets last verified green in the Epic 01/02 reviews and the lead's build log; tenant pages are authenticated and covered by the query-efficiency review instead (QG-PERF split) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | No N+1 | ⚠️ | Settings members list and pending-invitations modal are single-query/eager-loaded; the team switcher and teams index issue one `teamRole` query per team (F1) - bounded by teams-per-user (single digits), rendered in the chrome of every authenticated page; no query-count assertion exists yet (F2) |
| 2 | Query efficiency | ✅ | Membership checks are exists-queries on the unique (team_id, user_id) index; slug routing on unique slug; invitation lookups on unique code; `isLastOwner` is a single pluck; no per-row loops over aggregates |
| 3 | Lighthouse budget | n/a | No public pages added/changed; budgets green per Epic 01/02 evidence and lead build log |
| 4 | Server response budget | ✅ | Tenancy pages render small datasets (members, invitations); full browser suite (19 page visits) completes in seconds; nothing heavy added to the request path |
| 5 | Async | ✅ | Invitation mail queued (ShouldQueue, tested); no inline sending introduced |
| 6 | Reliability/concurrency | ✅ | Transfer/delete/create wrapped in transactions; `lockForUpdate` on ownership transfer prevents the demote/promote race; booking concurrency suite is Epic 06 |
| 7 | Asset weight | ✅ | No new JS/CSS bundles; Flux/Livewire components reuse the existing build |
| 8 | Caching | ✅ | `TeamName::reservedNames()` memoized per request via `once()`; marketing pages untouched |
| 9 | Observability | ✅ | Epic 00 correlation-ID + structured logging middleware unchanged; failed queue jobs visible via the existing failed-jobs table; prune command scheduled and tested |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | NFR-PERF-2 | `app/Concerns/HasTeams.php` (`toUserTeam()` calling `teamRole()` per team) | The team switcher (every authenticated page) and teams index run 1 + N queries for a user with N teams because the role is fetched per team instead of read from the already-loaded pivot (`teams()` includes `withPivot(['role'])`) | Read the role from the eager pivot in `toUserTeams()`; small change, removes N queries per page render |
| F2 | Medium | NFR-PERF-2 | tenancy screens | No query-count assertion test guards the tenancy list screens, so F1-style regressions are invisible; the gate formally targets list/calendar/dashboard views, which arrive in Epics 04+ | Add an `expectsDatabaseQueryCount`-style assertion for the teams index/switcher when fixing F1; mandatory for the Epic 04 list views |
| F3 | Low | NFR-PERF-1 | `⚡edit.blade.php` `populateTeamData()` + `timezones()` | The settings page rebuilds full member/invitation arrays after every action and ships ~400 timezone options twice per page (profile select); fine at current scale | Consider `#[Computed]` member lists or partial refresh if the page grows; defer |

## Required fixes (blocking)

- None.

## Initial decision (2026-06-10, first pass)

**PASS WITH WARNINGS**

- Rationale: no blocking trigger is hit - no N+1 on a data-heavy list/calendar/dashboard view (those screens arrive in later epics), email is queued, writes are atomic with row locking, and indexes back every hot lookup. The switcher's per-team role query (F1) and the missing query-count guard (F2) are real but bounded Medium items, tracked for the Epic 04 list-view work.
- Blocking findings remaining: 0

## Re-review after fixes (2026-06-10)

The fix round targeted the other reviews' blockers; re-verified that nothing regressed performance-wise:

- The `BelongsToTenant::creating` changes add only in-memory comparisons (one container read, one int cast) to the create path; `TeamName::isReserved()` reuses the `once()`-memoized list, and the slug-generation while-loop touches the DB zero extra times (it works on the already-fetched slug collection). No new queries on any hot path.
- `HasTeams::toUserTeam()` is unchanged, so F1 (per-team role query in the switcher/index) and F2 (no query-count assertion) remain open Medium items, tracked for the Epic 04 list-view work.
- Fresh run: `php artisan test --compact` 206/206 in ~12s including the 19 browser tests; no public pages changed, so the Lighthouse stance is unchanged.

## Final decision

**PASS WITH WARNINGS**

- Rationale: unchanged from the first pass - no blocking trigger; the fixes added no measurable request-path cost. F1/F2 stay tracked for Epic 04.
- Blocking findings remaining: 0
