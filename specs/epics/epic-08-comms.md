# Epic 08 — Customer self-service & communication

## Goal

Let customers manage their own appointments via a secure tokened link, and send
the confirmation, cancellation, and reminder emails — all queued and templated
with tenant branding.

## Requirements covered

FR-CANCEL-1 … FR-CANCEL-4, FR-COMMS-1 … FR-COMMS-4.

## In scope

- Extend the view-only `/manage/{token}` page from Epic 06 with **cancel and
  reschedule** actions (no login). The token and its SEC-TOKEN properties already
  exist from Epic 06; this epic adds the actions and enforces the cancellation
  cut-off from the tenant booking policy (FR-TENANT-8).
- Reschedule via the manage link using the Epic 05 slot engine and Epic 06
  conflict guarantee.
- **New in this epic:** the reminder mailable + its scheduled, idempotent job
  (reminder lead time from the booking policy). The confirmation mailable
  (Epic 06) and cancellation mailable (Epic 07) already exist; this epic ensures
  all three are queued, templated, and branded (tenant name + contact email) and
  polishes that branding — it does not introduce the confirmation/cancellation
  mailables.

## Out of scope

Real SMS/WhatsApp. Marketing emails.

## Acceptance criteria

- **AC-1 (SEC-TOKEN)** The manage link uses an unguessable token bound to exactly
  one appointment; tampering or a token for another appointment is rejected; no
  appointment data leaks without a valid token.
- **AC-2** A customer can cancel via the link; the slot is freed; a cancellation
  email is sent; cancellation is refused past the cut-off with a clear message.
- **AC-3** A customer can reschedule to another available slot atomically without
  creating a conflict.
- **AC-4** Confirmation and cancellation emails are enqueued (not sent inline)
  and contain correct details + a valid manage link.
- **AC-5** A scheduled command enqueues reminder emails for upcoming
  appointments within the configured window; the job is idempotent (no double
  reminders) and observable in the queue.
- **AC-6** Email templates render with tenant branding and pass basic HTML email
  sanity (no broken links/variables).

## Implementation notes

- Tokens: use a signed or random 32+ byte token stored hashed; compare in
  constant time; never expose raw tokens in logs.
- Reminders: a scheduled artisan command + queued mailables; mark reminded to
  ensure idempotency.
- Use `Mail::fake()`/`Queue::fake()` and `Notification::fake()` in tests; assert
  queueing and contents.

## Required tests

- Token security tests: valid token works; wrong/forged token is rejected;
  cross-appointment token denied.
- Cancel + reschedule flow tests incl. cut-off enforcement and slot freeing.
- Mail/queue assertion tests for confirmation, cancellation, reminder.
- Reminder idempotency test (running twice does not double-send).
- Accessibility: axe assertions in the tokened manage-page E2E test (QG-A11Y for
  the tokened page, per test-plan.md §Accessibility & performance per page).

## Done when

Meets [../definition-of-done.md](../definition-of-done.md); SEC-TOKEN review
passes; comms covered by queue/mail-fake tests; cancellation-token code (created
in Epic 06, actioned here) meets the elevated critical-logic coverage (≥ 95%) and
mutation (≥ 85%) targets per [../test-plan.md](../test-plan.md).
