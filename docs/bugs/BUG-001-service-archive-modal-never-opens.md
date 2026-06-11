# BUG-001: Service archive confirmation modal never opens

**Severity:** High
**Status:** Verified
**Area:** Services > Archive

## Summary

Clicking the archive button on a service row dispatches a `modal-show` event for the correct modal name, but the modal never appears. The confirmation dialog is silently swallowed.

## Steps to Reproduce

1. Log in as `demo@librenexus.test` / `password`.
2. Navigate to `/demo-clinic/services`.
3. Click the archive icon button on any active service row (e.g. "QA Test Service").
4. Observe: nothing happens. No modal opens.

## Expected Behaviour

A confirmation modal appears with the service name, a "Cancel" button, and a danger "Archive" button. Confirming sets `is_active = false` and removes the service from the active list.

## Actual Behaviour

No modal opens. The button click fires the Flux `modal-show` event (verified via browser DevTools) but there is no matching `<dialog>` element in the DOM for the targeted modal.

## Root Cause

In `/resources/views/pages/services/⚡index.blade.php` there are two separate `@foreach ($this->services as $service)` loops:

- **Line 249-264**: the table rows loop, which renders the `<flux:modal.trigger name="archive-service-{{ $service->id }}">` trigger.
- **Line 269-288**: the modal definitions loop, which renders `<flux:modal name="archive-service-{{ $service->id }}">`; this loop only renders a modal when `$service->is_active` is true (line 270).

When the Livewire component re-renders after a new service is created (or after any state change), the `$this->services` computed property may return a different object collection between the two loop evaluations - likely because the computed property is not cached identically across the two render passes, causing the second loop to omit the modal for a service that was included in the trigger loop (or vice versa). During the QA session, after creating "QA Test Service" (DB id=5), the modal loop rendered dialogs for ids 3 and 4 only, while the trigger loop correctly rendered a trigger for id 5.

Even without the caching issue, the pattern is fragile: the two loops are evaluated independently and must stay in sync. If `$this->services` returns results in different order or filtered differently between loops, triggers and modals will be mismatched.

## Affected File

- `resources/views/pages/services/⚡index.blade.php` lines 249-288

## Suggested Fix

Merge the modal rendering into the same `@foreach` loop as the table rows, or cache the `services` computed property so it is guaranteed to return the same collection for both loops within a single render cycle.

## Fix

Merged the archive modal into the same `@foreach` row loop as its trigger, so a trigger and its modal are always emitted from one iteration and can never desync. This is the pattern the Flux docs recommend for modals inside a loop ("If you are placing modals inside a loop... dynamically generate unique modal names"). The separate second loop was removed.

The same two-loop pattern existed on the staff page (deactivate confirmation) and was fixed identically.

Files changed:

- `resources/views/pages/services/⚡index.blade.php`: the `archive-service-{id}` modal now lives inside the row loop next to its trigger; the trailing modal-only loop was removed.
- `resources/views/pages/staff/⚡index.blade.php`: the `deactivate-staff-{id}` modal now lives inside the row loop next to its trigger; the trailing modal-only loop was removed.

Regression tests:

- `tests/Browser/StaffServicesSmokeTest.php`: "a freshly created service can be archived through its confirmation modal (BUG-001)" creates a service in the live UI, opens the new row's archive modal, confirms, and asserts the service becomes inactive. This reproduces the QA path (create, then archive) end to end.
- `tests/Feature/Services/ServiceManagementTest.php`: "a freshly created service renders its archive trigger and modal together (BUG-001)" asserts the trigger and its modal are both present for the new service in a single render.

Verification: `npm run build` was rerun so the assets reflect the markup change; the browser and feature suites are green.

## Re-verification (2026-06-11)

Tested in Chrome via Playwright at http://librenexus.test:

1. Navigated to `/demo-clinic/services`.
2. Created a new service "BUG001 Reverify Service" via the Add service modal.
3. Clicked the "Archive service" button on the newly created row.
4. The confirmation dialog appeared immediately inline with the row, showing the heading "Archive service", the service name message, and Cancel/Archive buttons.
5. Clicked "Archive" - received "Service archived." toast; the service disappeared from the active list.

Also tested on the staff page (same fix):

1. Created a new staff member "BUG001 Reverify Staff".
2. Clicked "Deactivate staff member" on the new row.
3. The deactivation confirmation dialog appeared with the staff name.
4. Clicked "Deactivate" - received "Staff member deactivated." toast; row status changed to "Inactive".

Both flows pass end-to-end. Fix confirmed.
