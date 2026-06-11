<?php

namespace App\Actions\Booking;

use App\Actions\Availability\GetBookableSlots;
use App\Data\BookedAppointment;
use App\Data\BookingRequest;
use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Book an appointment for a customer (FR-BOOK-2..5, FR-CUST-2). The slot is
 * re-validated through the engine inside the same transaction that inserts
 * the row, and the Postgres exclusion constraint (ADR-0003) is the final
 * arbiter under concurrency: a lost race surfaces as
 * SlotNoLongerAvailableException, never as a double booking.
 */
class BookAppointment
{
    private const string EXCLUSION_VIOLATION = '23P01';

    private const string UNIQUE_VIOLATION = '23505';

    public function __construct(private GetBookableSlots $getBookableSlots) {}

    public function handle(Team $team, BookingRequest $request): BookedAppointment
    {
        try {
            return $this->attempt($team, $request);
        } catch (QueryException $exception) {
            // Two first-time bookings with the same new email can race the
            // customer unique index; on retry the existing row is reused.
            if ($exception->getCode() === self::UNIQUE_VIOLATION) {
                return $this->attempt($team, $request);
            }

            throw $exception;
        }
    }

    protected function attempt(Team $team, BookingRequest $request): BookedAppointment
    {
        try {
            return DB::transaction(function () use ($team, $request): BookedAppointment {
                $service = Service::query()->bookable()->findOrFail($request->serviceId);
                $staff = $request->staffId === null
                    ? null
                    : Staff::query()->bookable()->findOrFail($request->staffId);

                $slot = $this->matchingSlot($team, $service, $staff, $request->startsAt);

                if ($slot === null) {
                    throw SlotNoLongerAvailableException::make();
                }

                $customer = $this->upsertCustomer($request);

                $rawToken = Str::lower(Str::random(48)).bin2hex(random_bytes(8));

                $appointment = Appointment::query()->create([
                    'staff_id' => $slot->staffId,
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'status' => $team->requires_approval
                        ? AppointmentStatus::Pending
                        : AppointmentStatus::Confirmed,
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'buffered_starts_at' => $slot->bufferedStartsAt,
                    'buffered_ends_at' => $slot->bufferedEndsAt,
                    'notes' => $request->notes,
                    'cancellation_token_hash' => hash('sha256', $rawToken),
                ]);

                return new BookedAppointment($appointment, $rawToken);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::EXCLUSION_VIOLATION) {
                throw SlotNoLongerAvailableException::make();
            }

            throw $exception;
        }
    }

    /**
     * Re-validate the requested start against the engine inside the booking
     * transaction (never trust the slot shown earlier). For "any available"
     * the first matching slot's staff member wins: the engine orders by
     * start time, ties broken by ascending staff id (deterministic, AC-7).
     */
    private function matchingSlot(Team $team, Service $service, ?Staff $staff, CarbonImmutable $startsAt): ?Slot
    {
        $localDate = $startsAt->setTimezone($team->timezone)->format('Y-m-d');

        return $this->getBookableSlots
            ->handle($team, $service, $staff, $localDate, $localDate)
            ->first(fn (Slot $slot): bool => $slot->startsAt->equalTo($startsAt));
    }

    /**
     * Create or reuse the tenant's customer record by case-insensitive
     * email, updating name and phone to the latest values (FR-CUST-2).
     */
    private function upsertCustomer(BookingRequest $request): Customer
    {
        $customer = Customer::query()->firstOrNew([
            'email' => Str::lower(trim($request->customerEmail)),
        ]);

        $customer->name = $request->customerName;
        $customer->phone = $request->customerPhone ?? $customer->phone;
        $customer->save();

        return $customer;
    }
}
