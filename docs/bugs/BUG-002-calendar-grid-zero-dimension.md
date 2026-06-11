# BUG-002: Calendar day view grid renders at 0x0 pixels

**Severity:** High
**Status:** Verified
**Area:** Calendar > Day view

## Summary

The calendar day view grid container has zero width and zero height, making all appointment blocks and time ruler labels invisible. The page renders a blank area where the calendar should appear.

## Steps to Reproduce

1. Log in as `demo@librenexus.test` / `password`.
2. Navigate to `/demo-clinic/calendar`.
3. Observe: the area below the date navigation header is blank. No time slots or appointment blocks are visible.

## Expected Behaviour

A scrollable time grid is displayed with one column per staff member, hour labels on the left, horizontal hour dividers, and coloured appointment blocks positioned at their correct time slots.

## Actual Behaviour

The calendar grid container (`[data-test="calendar-grid"]`) exists in the DOM and contains child column elements with appointment data, but `getBoundingClientRect()` returns `{ width: 0, height: 0 }`. All content is rendered but not visible.

Confirmed via browser evaluation:
```
document.querySelector('[data-test="calendar-grid"]').getBoundingClientRect()
// → { x: 0, y: 0, width: 0, height: 0, top: 0, right: 0, bottom: 0, left: 0 }
```

## Root Cause

In `/resources/views/pages/appointments/⚡calendar.blade.php` line 302, the grid container is:

```html
<div class="relative flex" data-test="calendar-grid">
```

The container uses `display: flex` but has no explicit height set. Its children are CSS Grid columns that each declare `grid-rows-[repeat(52,1.25rem)]` (52 quarter-hour rows of 1.25rem each = 65rem total height). However, CSS Grid row definitions only affect the height of the grid container itself - they do not propagate upward to parent flex items. Because no ancestor element constrains or provides a height to this flex container, it collapses to zero height, making the grid columns (which are `flex-1` items) also collapse.

The outer wrapper `div.min-w-[40rem]` at line 289 only sets a minimum width, not a height.

## Affected File

- `resources/views/pages/appointments/⚡calendar.blade.php` lines 288-302

## Suggested Fix

Add an explicit height to the grid container so the flex layout can size its children correctly. Since the grid covers 52 rows of `1.25rem` each, the total height is `52 * 1.25rem = 65rem`. Options:

1. Add `h-[65rem]` to the `calendar-grid` container div (matches the CSS Grid row template exactly).
2. Add `overflow-y-auto` and a max-height on the outer wrapper, e.g. `max-h-[40rem] overflow-y-auto`, and set `h-[65rem]` on the grid container.

Option 2 is likely preferable for usability (avoids a very tall non-scrollable page).

## Fix

Applied option 2. The grid container now carries an explicit height matching its CSS Grid row template (`h-[65rem]` = 52 rows of 1.25rem), so the flex parent no longer collapses, and the desktop wrapper scrolls (`max-h-[40rem] overflow-auto`) instead of forcing a very tall page.

A second contributing factor surfaced while reproducing: the calendar's arbitrary Tailwind classes (`grid-rows-[repeat(52,1.25rem)]` and the new `h-[65rem]`) were not present in the committed `public/build` CSS bundle, so even the row template produced no height. Rebuilding the assets (`npm run build`) regenerated them; with the height pinned, the grid lays out correctly.

Files changed:

- `resources/views/pages/appointments/⚡calendar.blade.php`: added `h-[65rem]` to the `calendar-grid` container and `max-h-[40rem] overflow-auto` to the desktop wrapper.
- `public/build/assets/*`: regenerated via `npm run build`.

Regression test:

- `tests/Browser/AppointmentsSmokeTest.php`: "the calendar day grid renders with visible dimensions (BUG-002)" loads the calendar at a desktop width and asserts `[data-test="calendar-grid"]` has non-zero width and height via `getBoundingClientRect()`, directly checking the symptom in the bug report. Measured 960x1040 after the fix.

## Re-verification (2026-06-11)

Tested in Chrome via Playwright at http://librenexus.test (viewport 1280x800):

1. Navigated to `/demo-clinic/calendar` (today: Thursday, June 11, 2026).
2. Measured grid via `getBoundingClientRect()`: **930x1040 px**, 4 appointment blocks across 3 staff columns. All blocks visible.
3. Screenshot confirmed: time ruler labels (07:00-14:00+), red "now" marker at ~08:00, coloured appointment blocks at 12:45 and 13:00 visible.
4. Clicked "Next day" (June 12): grid still 930x1040, 1 block present.
5. Clicked "Previous day" twice (June 10): grid still 930x1040, 1 block present. Grid dimensions stable across all navigation.
6. Resized to 390x844 (mobile): calendar correctly renders a list view per staff member (desktop grid is `hidden md:block`); appointments list visible with times and service names.

Fix confirmed. Grid no longer collapses and appointment blocks are fully visible at desktop widths.
