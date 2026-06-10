# Review Report - Product - Epic 04 (Staff & services)

## Reviewed scope

- **Epic / change:** Epic 04, working tree on `main` after commit `ddc740f` (Epic 03)
- **Requirements/rules in scope:** FR-STAFF-1..4, FR-SERVICE-1..3, FR-TENANT-5 (AC-7 surface), AC-1..AC-7 of `specs/epics/epic-04-staff-services.md`, `specs/pages.md` §Staff & services

## Files reviewed

- `resources/views/pages/staff/⚡index.blade.php` - staff list, form modal, link/unlink, deactivate/reactivate flows
- `resources/views/pages/services/⚡index.blade.php` - services list, form modal, archive/restore, archived filter
- `resources/views/dashboard.blade.php` - AC-7 unlinked staff-member callout
- `resources/views/layouts/app/sidebar.blade.php` - Staff/Services nav items replacing starter links
- `app/Models/Staff.php`, `app/Models/Service.php` - bookable scopes, attributes
- `app/Enums/CalendarColor.php` - the documented 8-color palette
- `routes/web.php` - `{current_team}/staff` and `{current_team}/services` named routes
- `specs/pages.md` lines 125-146 - page element checklists

## Flows reviewed

- Admin creates staff with service assignment, edits, deactivates with confirm modal, reactivates - all via the staff page
- Admin links a staff record to a membership and unlinks again; self-link blocked with an actionable message
- Owner creates/edits a service with duration/buffers/price; archives with confirm modal; restores; toggles the archived filter
- Staff-role member opens both list pages (read-only, no action buttons rendered) and the dashboard (AC-7 callout when unlinked)
- Browser happy path: real form submission over Livewire update requests verified by a scratch browser test during this review (staff row created, correct tenant)

## Tests reviewed

- `tests/Feature/Staff/StaffManagementTest.php` - AC-1, AC-5, AC-6 flows incl. self-link denial, foreign-team membership denial, double-link denial, unlink-on-member-removal
- `tests/Feature/Services/ServiceManagementTest.php` - AC-2 bounds datasets (4/5/480/481, -1/0/120/121, price -1/null/0/cap), archive/restore, archived filter
- `tests/Feature/DashboardTest.php` - AC-7 notice shown only to unlinked staff-role members
- `tests/Browser/StaffServicesSmokeTest.php` - lists and modals render without JS errors

## Tools executed

| Command | Result | Notes |
|---------|--------|-------|
| `make test` | pass | 257/257, 816 assertions |
| `make e2e` | pass | 23/23 browser tests |
| scratch browser test (form submit end to end) | pass | staff row persisted with correct `team_id`, removed after review |

## Checklist results

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | AC coverage | ⚠️ | AC-1/2/5/6 fully demonstrated by the tests above. AC-3/AC-4 are implemented (`scopeBookable`, pivot) but "past appointments remain" and "respected by the slot engine" cannot be fully proven until Epics 05-07; see F1 |
| 2 | MUST requirements | ✅ | FR-STAFF-1/2/4, FR-SERVICE-1/2/3 satisfied (tests cited above); FR-STAFF-3 (SHOULD) implemented via `service_staff` pivot |
| 3 | Pages present | ✅ | `/{tenant}/staff` and `/{tenant}/services` exist, return 200 (feature tests), elements match pages.md; staff "active toggle" rendered as status badge + deactivate/reactivate actions with confirm modal, see F3 |
| 4 | Happy path works | ✅ | Feature tests cover create/edit/deactivate/archive; review scratch browser test proved the real-browser create path end to end |
| 5 | Validation & errors | ✅ | Server-side rules with inline `flux:error`; self-link returns a clear message ("Another admin has to do that"); modal reopens on errors (`:show="$errors->isNotEmpty()"`) |
| 6 | Empty / loading / error states | ✅ | Both pages have empty states with a primary CTA (`data-test="staff-empty-state"`, `services-empty-state`); success toasts on every action |
| 7 | Copy | ✅ | Action-oriented, glossary-consistent ("Deactivate", "Archive", "Past appointments are kept."); no em-dashes in UI strings (grep) |
| 8 | Navigation & links | ✅ | Sidebar items use named routes with `{current_team}` URL defaults; starter-kit links replaced |
| 9 | Scope discipline | ✅ | No availability/booking features; price cap 10,000,000 minor units is a reasonable implementation constraint above the spec, see F2 |
| 10 | Onboarding / discoverability | ✅ | AC-7 callout tells an unlinked staff member exactly what must happen next (admin links them) |

## Findings

| ID | Severity | Rule | Location | Description | Required fix |
|----|----------|------|----------|-------------|--------------|
| F1 | Medium | AC-3 / AC-4 | epic ACs vs. current schema | "Past appointments remain intact" and "assignment respected by the slot engine" cannot be fully demonstrated before appointments (Epic 06/07) and the slot engine (Epic 05) exist. Tests prove record retention and bookable-scope exclusion only. No deferral note exists yet in `docs/assumptions.md` | Add a tracked deferral note; re-verify AC-3/AC-4 with appointment fixtures in Epics 05-07 |
| F2 | Low | FR-SERVICE-3 | `services/⚡index.blade.php:93` | Price capped at 10,000,000 minor units; the spec only demands non-negative. Sensible, but undocumented | Document the cap as an assumption |
| F3 | Low | pages.md | staff list | pages.md sketches an "active toggle" in the table; delivered as badge + explicit deactivate (confirm modal) / reactivate buttons. Arguably safer UX, but a deviation from the sketch | None required; noted for the record |
| F4 | Low | UX | service form | Price entered in raw minor units (2500 = 25.00). Description text explains it, but a currency-formatted input would be friendlier | Consider in Epic 10 polish |

## Required fixes (blocking)

- None.

## Final decision

**PASS WITH WARNINGS**

- Rationale: every in-scope AC is implemented and demonstrated to the extent currently possible; the only substantive gap is the cross-epic part of AC-3/AC-4, which needs a tracked deferral note (F1).
- Blocking findings remaining: 0
