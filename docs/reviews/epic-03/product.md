# Review Report - Product - Epic 03 (Tenancy & isolation)

## Reviewed scope

- **Epic / change:** Epic 03 (Tenancy & isolation), current working tree
- **Requirements/rules in scope:** FR-TENANT-1..10, FR-SETTINGS-3, AC-1..AC-9, pages.md §Tenant management

## Files reviewed

- `resources/views/pages/teams/⚡edit.blade.php` - tenant settings page: profile (name, slug with URL preview, timezone, contact email, locale, currency), booking policy with bounds and defaults shown, members with role change / make owner / remove, pending invitations with revoke, danger zone (FR-TENANT-8, FR-SETTINGS-3)
- `resources/views/pages/teams/⚡index.blade.php`, `resources/views/components/⚡create-team-modal.blade.php`, `resources/views/components/⚡team-switcher.blade.php` - tenant list, create (name + timezone + contact email), switcher with "New team" (FR-TENANT-1/2/3)
- `resources/views/pages/teams/⚡accept-invitation.blade.php`, `⚡pending-invitations-modal.blade.php`, `⚡invite-member-modal.blade.php`, `⚡cancel-invitation-modal.blade.php` - invitation lifecycle UI (FR-TENANT-6/7)
- `resources/views/components/team-invitation-alert.blade.php` + `resources/views/pages/auth/{login,register}.blade.php` - unregistered-invitee context on auth pages (AC-4)
- `app/Actions/Teams/{CreateTeam,TransferTeamOwnership,DeleteUserWithTenants}.php` - creation defaults, ownership transfer, account-deletion guard (FR-TENANT-8/9/10)
- `app/Rules/{TeamName,TeamSlug}.php`, `app/Concerns/GeneratesUniqueTeamSlugs.php` - slug format/uniqueness/reserved rules (AC-1, AC-8)
- `app/Concerns/HasTeams.php` - personal team naming `personalTeamName()` = "{first}'s Office" (FR-TENANT-1)
- `database/migrations/2026_06_10_204507_*` - booking-policy defaults 120min/60d/120min/24h/approval-off (FR-TENANT-8)

## Flows reviewed

- Create tenant (index page and switcher modal) -> redirected to settings; defaults present; slug auto-generated and stable on rename, editable with validation
- Switch tenant via switcher and via deep link; non-member deep link -> 404 (AC-5)
- Invite member (owner/admin) -> queued email -> login/register pages show invitation alert -> dashboard pending-invitations modal -> accept (role applied, single-use) / decline; revoke from settings (AC-4)
- Role management: change role, last-owner demote/remove blocked with actionable error copy, transfer ownership ("Your role will become admin"), delete team with name confirmation (AC-2, AC-7)
- Account deletion blocked while sole owner of a non-personal tenant with a message naming the blocking team (FR-TENANT-10)
- Member removal: membership deleted, removed member's current team falls back to their personal team (AC-9, first half)

## Tests reviewed

- `tests/Feature/Tenancy/TenantSettingsTest.php` - defaults after creation, profile/policy editable by owner+admin and denied for staff, slug change + old URL 404, reserved/duplicate/format slug rejection, policy bounds (AC-1, AC-6, AC-8)
- `tests/Feature/Tenancy/OwnershipTest.php` - transfer, last-owner demote/remove blocks, co-owner demote allowed, account-deletion block and success-after-transfer (AC-7)
- `tests/Feature/Teams/TeamInvitationTest.php` - create, 7-day expiry, expired/foreign-email acceptance refused, revoke, accept joins with assigned role (AC-4)
- `tests/Feature/Tenancy/{IsolationTest,TeamMembershipMiddlewareTest}.php` - cross-tenant denial, 404 for non-members (AC-3, AC-5)
- `tests/Feature/Teams/{TeamTest,TeamMemberTest}.php` - CRUD, slug suffixing, delete confirmation, personal-team delete refusal, member removal (AC-1, AC-9)
- `tests/Browser/TenancySmokeTest.php` - tenant settings, teams index, accept-invitation journey render without JS errors

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `php artisan test --compact` | pass | 193/193, 568 assertions - fresh run (includes the 19 browser tests) |
| `php artisan test tests/Feature/Tenancy tests/Feature/Teams ...` | pass | 105/105 tenancy-focused tests - fresh run |
| tinker probe (`Str::slug("Pricing!")` vs `TeamName`) | **bypass confirmed** | name "Pricing!" accepted, generated slug = reserved "pricing" (F2) |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ❌ | AC-1 ✅, AC-3 ✅, AC-5 ✅, AC-6 ✅, AC-7 ✅; AC-2 ❌ (F1: server accepts an `owner` invitation role from an admin, so roles do not grant "exactly" FR-TENANT-5 permissions); AC-8 ❌ (F2: creation path can mint a reserved slug); AC-4 ⚠️ (works, but the unregistered journey itself is untested, see QA); AC-9 ⚠️ (staff-record half deferred to Epic 04, F3) |
| 2 | MUST requirements | ⚠️ | FR-TENANT-1/2/3/4/8/9/10 + FR-SETTINGS-3 satisfied with tests; FR-TENANT-5 violated by F1; FR-TENANT-6 satisfied |
| 3 | Pages present | ✅ | Tenant switcher, create-tenant modal, tenant settings (sectioned: Profile / Booking policy / Members / Danger zone per pages.md visual), accept-invitation route; all render 200 in tests |
| 4 | Happy path works | ✅ | Create -> settings -> invite -> accept -> switch covered by feature + browser tests (TenancySmokeTest accept journey lands on team dashboard) |
| 5 | Validation & errors | ✅ | Slug format/reserved/unique messages, policy bounds with units and defaults in descriptions, last-owner errors tell the user what to do ("Transfer ownership or delete the team instead.") |
| 6 | Empty / loading / error states | ✅ | Pending-invitations section hidden when empty; modals use `wire:loading.attr="disabled"`; teams index has team rows with roles |
| 7 | Copy | ✅ | Clear, action-oriented; no em-dashes in the epic's views (grep clean); glossary-consistent ("team", "owner", "booking policy") |
| 8 | Navigation & links | ✅ | Named routes throughout; slug carried by `{current_team}` routes; slug-change test proves old URL 404s and new resolves |
| 9 | Scope discipline | ⚠️ | No scope creep; but the AC-9 staff-record deferral is not recorded in docs/assumptions.md (F3) |
| 10 | Onboarding / discoverability | n/a | FR-DASH-2 arrives with Epic 09; switcher "New team" entry exists |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | High | AC-2 / FR-TENANT-5 | `resources/views/pages/teams/⚡invite-member-modal.blade.php` (`Rule::enum(TeamRole::class)`) | The invite role is validated against the full enum, which includes `owner`. The UI only offers Admin/Staff, but a Livewire property can be set to `owner` by any admin, and acceptance applies the invitation role verbatim. An admin can therefore mint a new owner, which FR-TENANT-5 reserves for owners. Server must be the source of truth (AC-2) | Validate against `TeamRole::assignable()` values (exclude Owner); add a regression test |
| F2 | High | AC-8 / ARCH-ROUTING-5 | `app/Concerns/GeneratesUniqueTeamSlugs.php` + `app/Models/Team.php` boot | Slug auto-generation never consults the reserved list. `TeamName` checks the raw lowercased name, but `Str::slug()` normalizes punctuation: creating a team named "Pricing!" passes validation and mints the reserved slug `pricing` (confirmed by probe). The rename path is protected by `TeamSlug`; the creation path is not | Run generated slugs through the reserved-name check (suffix or reject); add a creation-path reserved-slug test |
| F3 | Medium | AC-9 / FR-TENANT-7 | member-removal flow | The "removing a member linked to a staff record unlinks the membership but preserves the staff record and its history" half of AC-9 cannot exist before the Staff model (Epic 04) and is not recorded as an assumption/deferral | Add a docs/assumptions.md note and carry an explicit Epic 04 task + test for the unlink-preserves-history behavior |
| F4 | Low | pages.md §Tenant management | `⚡pending-invitations-modal.blade.php`, `⚡create-team-modal.blade.php` | Accept UI shows team name + inviter but not the assigned role (pages.md lists "tenant name, assigned role, accept/decline"); the create modal has no auto-suggested slug preview (slug appears first in settings) | Show the invited role in the modal; consider a slug preview in the create modal; track for polish |

## Required fixes (blocking)

- F1: restrict the invitation role to assignable roles server-side (AC-2 / FR-TENANT-5).
- F2: route auto-generated slugs through the reserved-name check (AC-8).

## Initial decision (2026-06-10, first pass)

**FAIL**

- Rationale: the feature set is complete, well-copy-edited, and the primary journeys all work with test evidence, but two acceptance criteria are unmet: AC-2 (an admin can grant ownership via a forged invitation role) and AC-8 (team creation can mint a reserved slug such as `pricing`). Both have one-line fixes.
- Blocking findings remaining: 2 (F1, F2)

## Re-review after fixes (2026-06-10)

Verified by reading the changed code/tests and fresh runs:

- **F1 resolved.** `⚡invite-member-modal.blade.php` now validates `inviteRole` with `Rule::enum(TeamRole::class)->except(TeamRole::Owner)` plus an intent comment ("Owner is never assignable via invitation; ownership is granted only through the explicit transfer flow"). New test `TeamInvitationTest::an invitation can never carry the owner role`: an admin forcing `inviteRole = owner` gets a validation error on `inviteRole` and `assertDatabaseMissing` proves nothing persisted. AC-2 now holds with the server as source of truth.
- **F2 resolved.** `GeneratesUniqueTeamSlugs` consults the new single-source `TeamName::isReserved()` and falls through to a numeric suffix (while-loop guarded). Probe re-run: "Pricing!" -> `pricing-1`, "Login" -> `login-1`, "Acme Clinic" -> `acme-clinic` (normal names unaffected). Dataset test `TeamTest::auto-generated slugs never shadow a reserved path` (Pricing/Login/Book/Health/Settings) asserts the generated slug is never reserved. AC-8 now holds for creation and rename.
- **F3 resolved.** docs/assumptions.md §Tenancy now records the AC-9 deferral explicitly: the staff-unlink-preserves-record half ships with the Staff model in Epic 04 (with its test); member removal incl. last-owner guard ships here.
- **AC-4 strengthened:** the unregistered-invitee journey is now test-backed (`the register page carries the invitation context for an unregistered invitee`: valid code shows the team name on `/register`, bogus code does not) and an unverified invitee is redirected to `verification.notice` with no membership created.
- F4 (Low: assigned role not shown in the pending-invitations modal, no slug preview in the create modal) remains open and tracked for polish.
- Fresh runs: `php artisan test --compact` 206/206 (602 assertions, browser suite included); tinker slug probe as above.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every in-scope MUST and acceptance criterion is met with test evidence (AC-9's staff half formally deferred to Epic 04 in assumptions); the only remaining items are Low UX polish nits (F4), tracked.
- Blocking findings remaining: 0
