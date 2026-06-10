# ADR-0003: Double-booking prevention strategy

- Status: accepted
- Date: 2026-06-10

## Context

FR-BOOK-3 (critical): two customers must never book the same staff member for
overlapping times, even under concurrent requests. ARCH-DATA-3 allows either a
Postgres exclusion constraint on a time range or a unique constraint plus row
locking. Application-level checks alone are insufficient. Per FR-APPT-4 only
time-reserving statuses (`pending`, `confirmed`) may block a slot.

## Decision

Use a **PostgreSQL exclusion constraint** as the source of truth:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap
  EXCLUDE USING gist (
    staff_id WITH =,
    tstzrange(buffered_starts_at, buffered_ends_at) WITH &&
  )
  WHERE (status IN ('pending', 'confirmed'));
```

- The range covers the appointment **including its service buffers**
  (`buffered_starts_at` / `buffered_ends_at` stored alongside `starts_at` /
  `ends_at`), so back-to-back bookings respect buffer rules at the DB level.
- The partial `WHERE` clause means `cancelled` / `no_show` / `completed` rows
  never block new bookings (FR-APPT-4, FR-CANCEL-4).
- The booking/reschedule action runs in a transaction: re-validate the slot
  via the slot engine, insert/update, and translate a
  `23P01 exclusion_violation` into a domain-level "slot no longer available"
  error for the user. Reschedule updates the same row, so constraint checking
  is atomic with the move.

## Alternatives considered

- **Unique constraint on (staff_id, starts_at) + `SELECT ... FOR UPDATE`**:
  only protects identical start times, not arbitrary overlaps (different
  durations/buffers), and needs careful lock ordering. Rejected.
- **Application check inside a serializable transaction**: correct but causes
  retry storms and is easy to regress. Rejected as the primary guarantee
  (slot re-validation is still done in the transaction for good UX).

## Consequences

- Requires the `btree_gist` extension (created in a migration).
- Overlap is impossible regardless of code paths (public booking, manual
  create, reschedule all hit the same constraint).
- The named concurrency suite `tests/Feature/Booking/ConcurrencyTest.php`
  exercises the constraint path directly (parallel inserts), not just the app
  check.
