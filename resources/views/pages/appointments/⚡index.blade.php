<?php

use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\TransitionAppointmentStatus;
use App\Actions\Availability\GetBookableSlots;
use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Mail\AppointmentConfirmationMail;
use App\Mail\AppointmentRescheduledMail;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Appointment list for the active tenant (FR-APPT-1, Epic 07): filterable
 * by staff, service, status, and date range. Staff-role members are
 * restricted to their own appointments in the query itself, not just the
 * UI (FR-APPT-2, SEC-AUTHZ); every mutating action re-checks the
 * AppointmentPolicy server-side. Manual create, reschedule, and status
 * transitions reuse the Epic 06 booking actions, so the concurrency
 * guarantee is identical to public booking (FR-APPT-3, AC-3).
 */
new #[Title('Appointments')] class extends Component
{
    use WithPagination;

    private const int PER_PAGE = 25;

    #[Locked]
    public Team $team;

    #[Url]
    public string $staffFilter = '';

    #[Url]
    public string $serviceFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $fromDate = '';

    #[Url]
    public string $untilDate = '';

    #[Locked]
    public ?int $detailAppointmentId = null;

    #[Locked]
    public ?int $cancelAppointmentId = null;

    #[Locked]
    public ?int $rescheduleAppointmentId = null;

    public string $rescheduleDate = '';

    public ?int $newServiceId = null;

    public ?int $newStaffId = null;

    public string $newDate = '';

    /** UTC start instant of the chosen slot (ISO 8601), set server-side. */
    #[Locked]
    public ?string $newSlot = null;

    public string $customerName = '';

    public string $customerEmail = '';

    public string $customerPhone = '';

    public string $newNotes = '';

    public function mount(Team $current_team): void
    {
        $this->team = $current_team->refresh();

        Gate::authorize('viewAny', [Appointment::class, $this->team]);

        if ($this->localDate($this->fromDate) === null) {
            $this->fromDate = CarbonImmutable::now($this->team->timezone)->format('Y-m-d');
        }
    }

    /**
     * Jump back to the first page whenever a list filter changes, so the
     * filtered result never starts on a stale page.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['staffFilter', 'serviceFilter', 'statusFilter', 'fromDate', 'untilDate'], true)) {
            $this->resetPage();
        }
    }

    public function openDetail(int $appointmentId): void
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);

        Gate::authorize('view', $appointment);

        $this->detailAppointmentId = $appointment->id;

        Flux::modal('appointment-detail')->show();
    }

    public function transitionStatus(int $appointmentId, string $status): void
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);

        Gate::authorize('update', $appointment);

        $next = AppointmentStatus::tryFrom($status);

        if ($next === null) {
            throw ValidationException::withMessages([
                'status' => __('Unknown appointment status.'),
            ]);
        }

        app(TransitionAppointmentStatus::class)->handle($appointment, $next);

        unset($this->appointments);

        Flux::toast(variant: 'success', text: __('Appointment marked as :status.', ['status' => $next->label()]));
    }

    public function openCancelModal(int $appointmentId): void
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);

        Gate::authorize('update', $appointment);

        $this->cancelAppointmentId = $appointment->id;

        Flux::modal('cancel-appointment')->show();
    }

    public function confirmCancel(): void
    {
        $appointment = Appointment::query()->findOrFail($this->cancelAppointmentId);

        Gate::authorize('update', $appointment);

        app(TransitionAppointmentStatus::class)->handle($appointment, AppointmentStatus::Cancelled);

        $this->cancelAppointmentId = null;
        unset($this->appointments);

        Flux::modal('cancel-appointment')->close();
        Flux::toast(variant: 'success', text: __('Appointment cancelled. The customer has been notified.'));
    }

    public function openRescheduleModal(int $appointmentId): void
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);

        Gate::authorize('update', $appointment);

        $this->rescheduleAppointmentId = $appointment->id;
        $this->rescheduleDate = $appointment->starts_at->setTimezone($this->team->timezone)->format('Y-m-d');
        $this->resetErrorBag();
        unset($this->rescheduleAppointment, $this->rescheduleSlots);

        Flux::modal('reschedule-appointment')->show();
    }

    public function updatedRescheduleDate(): void
    {
        unset($this->rescheduleSlots);
    }

    public function rescheduleTo(string $startsAt): void
    {
        $appointment = Appointment::query()->findOrFail($this->rescheduleAppointmentId);

        Gate::authorize('update', $appointment);

        try {
            app(RescheduleAppointment::class)->handle($appointment, CarbonImmutable::parse($startsAt)->utc());
        } catch (SlotNoLongerAvailableException $exception) {
            unset($this->rescheduleSlots);

            throw ValidationException::withMessages([
                'rescheduleSlot' => $exception->getMessage(),
            ]);
        }

        // FR-APPT-5: the customer is told about the new time. The raw
        // manage token is never stored, so the admin path sends the notice
        // without a manage link; the customer keeps the link from their
        // confirmation email.
        Mail::to($appointment->customer->email)->queue(new AppointmentRescheduledMail($appointment));

        $this->rescheduleAppointmentId = null;
        unset($this->appointments, $this->rescheduleAppointment, $this->rescheduleSlots);

        Flux::modal('reschedule-appointment')->close();
        Flux::toast(variant: 'success', text: __('Appointment rescheduled.'));
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', [Appointment::class, $this->team]);

        $this->reset('newServiceId', 'newStaffId', 'newSlot', 'customerName', 'customerEmail', 'customerPhone', 'newNotes');
        $this->newDate = CarbonImmutable::now($this->team->timezone)->format('Y-m-d');
        $this->newStaffId = $this->canManage ? null : $this->ownStaff?->id;
        $this->resetErrorBag();
        unset($this->newSlots, $this->newStaffOptions);

        Flux::modal('new-appointment')->show();
    }

    public function updatedNewServiceId(): void
    {
        $this->newStaffId = $this->canManage ? null : $this->ownStaff?->id;
        $this->newSlot = null;
        unset($this->newSlots, $this->newStaffOptions);
    }

    public function updatedNewStaffId(): void
    {
        $this->newSlot = null;
        unset($this->newSlots);
    }

    public function updatedNewDate(): void
    {
        $this->newSlot = null;
        unset($this->newSlots);
    }

    public function selectNewSlot(string $startsAt): void
    {
        $slot = $this->newSlots->first(
            fn (Slot $slot): bool => $slot->startsAt->toIso8601String() === $startsAt,
        );

        if ($slot === null) {
            throw ValidationException::withMessages([
                'newSlot' => __('This time is no longer available. Please pick another slot.'),
            ]);
        }

        $this->newSlot = $slot->startsAt->toIso8601String();
        $this->resetErrorBag('newSlot');
    }

    public function createAppointment(): void
    {
        Gate::authorize('create', [Appointment::class, $this->team]);

        $validated = $this->validate([
            'customerName' => ['required', 'string', 'max:255'],
            'customerEmail' => ['required', 'string', 'email', 'max:255'],
            'customerPhone' => ['nullable', 'string', 'max:64'],
            'newNotes' => ['nullable', 'string', 'max:2000'],
            'newServiceId' => ['required', 'integer', Rule::exists('services', 'id')->where('team_id', $this->team->id)],
            'newStaffId' => ['required', 'integer', Rule::exists('staff', 'id')->where('team_id', $this->team->id)],
        ]);

        $this->ensureOwnStaffRecord($validated['newStaffId']);

        if ($this->newSlot === null) {
            throw ValidationException::withMessages([
                'newSlot' => __('Please pick a time slot.'),
            ]);
        }

        try {
            $booked = app(BookAppointment::class)->handle($this->team, new BookingRequest(
                serviceId: $validated['newServiceId'],
                staffId: $validated['newStaffId'],
                startsAt: CarbonImmutable::parse($this->newSlot)->utc(),
                customerName: $validated['customerName'],
                customerEmail: $validated['customerEmail'],
                customerPhone: $validated['customerPhone'] !== '' ? $validated['customerPhone'] : null,
                notes: $validated['newNotes'] !== '' ? $validated['newNotes'] : null,
            ));
        } catch (SlotNoLongerAvailableException $exception) {
            $this->newSlot = null;
            unset($this->newSlots);

            throw ValidationException::withMessages([
                'newSlot' => $exception->getMessage(),
            ]);
        }

        Mail::to($booked->appointment->customer->email)->queue(
            new AppointmentConfirmationMail($booked->appointment, $booked->rawManageToken),
        );

        unset($this->appointments);

        Flux::modal('new-appointment')->close();
        Flux::toast(variant: 'success', text: __('Appointment created. The customer has been notified.'));
    }

    /**
     * A staff-role member may create appointments only for the staff
     * record linked to their own membership (FR-APPT-2); the create()
     * policy ability has no target model, so the restriction is enforced
     * here against the validated staff id.
     */
    private function ensureOwnStaffRecord(int $staffId): void
    {
        if ($this->canManage || $staffId === $this->ownStaff?->id) {
            return;
        }

        throw ValidationException::withMessages([
            'newStaffId' => __('You can only create appointments for yourself.'),
        ]);
    }

    /**
     * The visible appointments: tenant-scoped, filtered, and restricted to
     * the user's own staff record for staff-role members in the query
     * itself (FR-APPT-2, AC-2). Relations are eager loaded (NFR-PERF) and
     * the list is paginated so it stays bounded at any volume (Epic 10).
     *
     * @return LengthAwarePaginator<int, Appointment>
     */
    #[Computed]
    public function appointments(): LengthAwarePaginator
    {
        $fromUtc = $this->localDate($this->fromDate)?->startOfDay()->utc();
        $untilUtc = $this->localDate($this->untilDate)?->addDay()->startOfDay()->utc();

        return Appointment::query()
            ->with(['staff', 'service', 'customer'])
            ->when(! $this->canManage, fn ($query) => $query->where('staff_id', $this->ownStaff?->id ?? 0))
            ->when($this->canManage && $this->staffFilter !== '', fn ($query) => $query->where('staff_id', (int) $this->staffFilter))
            ->when($this->serviceFilter !== '', fn ($query) => $query->where('service_id', (int) $this->serviceFilter))
            ->when(AppointmentStatus::tryFrom($this->statusFilter), fn ($query, AppointmentStatus $status) => $query->where('status', $status))
            ->when($fromUtc, fn ($query, CarbonImmutable $from) => $query->where('starts_at', '>=', $from))
            ->when($untilUtc, fn ($query, CarbonImmutable $until) => $query->where('starts_at', '<', $until))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return EloquentCollection<int, Staff>
     */
    #[Computed]
    public function staffOptions(): EloquentCollection
    {
        return Staff::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return EloquentCollection<int, Service>
     */
    #[Computed]
    public function serviceOptions(): EloquentCollection
    {
        return Service::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function detailAppointment(): ?Appointment
    {
        return $this->detailAppointmentId === null
            ? null
            : Appointment::query()->with(['staff', 'service', 'customer'])->find($this->detailAppointmentId);
    }

    #[Computed]
    public function cancelAppointment(): ?Appointment
    {
        return $this->cancelAppointmentId === null
            ? null
            : Appointment::query()->with(['staff', 'service', 'customer'])->find($this->cancelAppointmentId);
    }

    #[Computed]
    public function rescheduleAppointment(): ?Appointment
    {
        return $this->rescheduleAppointmentId === null
            ? null
            : Appointment::query()->with(['staff', 'service'])->find($this->rescheduleAppointmentId);
    }

    /**
     * Open slots for the reschedule day, excluding the appointment's own
     * range so its current time never blocks the move (FR-APPT-3).
     *
     * @return Collection<int, Slot>
     */
    #[Computed]
    public function rescheduleSlots(): Collection
    {
        $appointment = $this->rescheduleAppointment;
        $localDate = $this->localDate($this->rescheduleDate)?->format('Y-m-d');

        if ($appointment === null || $localDate === null) {
            return collect();
        }

        return app(GetBookableSlots::class)->handle(
            team: $this->team,
            service: $appointment->service,
            staff: $appointment->staff,
            fromDate: $localDate,
            untilDate: $localDate,
            excludeAppointmentId: $appointment->id,
        );
    }

    /**
     * Bookable staff for the chosen service in the new-appointment form.
     *
     * @return EloquentCollection<int, Staff>
     */
    #[Computed]
    public function newStaffOptions(): EloquentCollection
    {
        $service = $this->newServiceId === null
            ? null
            : Service::query()->bookable()->find($this->newServiceId);

        return $service?->staff()->bookable()->orderBy('name')->get()
            ?? new EloquentCollection;
    }

    /**
     * Open slots for the new-appointment day, service, and staff member.
     *
     * @return Collection<int, Slot>
     */
    #[Computed]
    public function newSlots(): Collection
    {
        $service = $this->newServiceId === null
            ? null
            : Service::query()->bookable()->find($this->newServiceId);
        $staff = $this->newStaffId === null
            ? null
            : Staff::query()->bookable()->find($this->newStaffId);
        $localDate = $this->localDate($this->newDate)?->format('Y-m-d');

        if ($service === null || $staff === null || $localDate === null) {
            return collect();
        }

        return app(GetBookableSlots::class)->handle($this->team, $service, $staff, $localDate, $localDate);
    }

    /**
     * Owners and admins manage every appointment of the team (FR-APPT-2).
     */
    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Gate::allows('create', [Appointment::class, $this->team]);
    }

    /**
     * The staff record linked to the acting user's own membership, if any:
     * the "own" appointments of a staff-role member (FR-APPT-2).
     */
    #[Computed]
    public function ownStaff(): ?Staff
    {
        return Auth::user()->staffRecordFor($this->team);
    }

    /**
     * Parse a Y-m-d date string in the tenant timezone, or null when the
     * value is empty or malformed (filter values arrive via the URL).
     */
    private function localDate(string $date): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->team->timezone) ?: null;
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Appointments') }}</flux:heading>
            <flux:subheading>{{ __('All bookings of your team, in :timezone time', ['timezone' => $team->timezone]) }}</flux:subheading>
        </div>

        @if ($this->canCreate)
            <flux:button variant="primary" icon="plus" wire:click="openCreateForm" data-test="new-appointment-button">
                {{ __('New appointment') }}
            </flux:button>
        @endif
    </div>

    <div class="sticky top-0 z-10 mt-6 flex flex-wrap items-end gap-3 border-b border-zinc-200 bg-white py-3 dark:border-zinc-700 dark:bg-zinc-800" data-test="appointments-filters">
        @if ($this->canManage)
            <flux:select wire:model.live="staffFilter" :label="__('Staff')" class="max-w-44" data-test="filter-staff">
                <flux:select.option value="">{{ __('All staff') }}</flux:select.option>
                @foreach ($this->staffOptions as $staffMember)
                    <flux:select.option value="{{ $staffMember->id }}">{{ $staffMember->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @else
            <flux:select disabled :label="__('Staff')" class="max-w-44" data-test="filter-staff-locked">
                <flux:select.option selected>{{ $this->ownStaff?->name ?? __('No staff record') }}</flux:select.option>
            </flux:select>
        @endif

        <flux:select wire:model.live="serviceFilter" :label="__('Service')" class="max-w-44" data-test="filter-service">
            <flux:select.option value="">{{ __('All services') }}</flux:select.option>
            @foreach ($this->serviceOptions as $service)
                <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="statusFilter" :label="__('Status')" class="max-w-36" data-test="filter-status">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (\App\Enums\AppointmentStatus::cases() as $statusOption)
                <flux:select.option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live="fromDate" type="date" :label="__('From')" class="max-w-40" data-test="filter-from" />
        <flux:input wire:model.live="untilDate" type="date" :label="__('Until')" class="max-w-40" data-test="filter-until" />
    </div>

    <div class="mt-6">
        @if ($this->appointments->isEmpty())
            <div class="rounded-xl border border-zinc-200 px-6 py-12 text-center dark:border-zinc-700" data-test="appointments-empty-state">
                <flux:heading>{{ __('No appointments found') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Try widening the filters, or create an appointment for a customer.') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column>{{ __('Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Service') }}</flux:table.column>
                    <flux:table.column>{{ __('Staff') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>
                        <span class="sr-only">{{ __('Actions') }}</span>
                    </flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->appointments as $appointment)
                        <flux:table.row :key="$appointment->id" data-test="appointment-row">
                            <flux:table.cell variant="strong">
                                {{ $appointment->starts_at->setTimezone($team->timezone)->isoFormat('ddd, MMM D, YYYY') }}
                                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">
                                    {{ $appointment->starts_at->setTimezone($team->timezone)->format('H:i') }}
                                    -
                                    {{ $appointment->ends_at->setTimezone($team->timezone)->format('H:i') }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>{{ $appointment->customer->name }}</flux:table.cell>
                            <flux:table.cell>{{ $appointment->service->name }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-3 rounded-full" style="background-color: {{ $appointment->staff->color->hex() }}" aria-hidden="true"></span>
                                    {{ $appointment->staff->name }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>
                                <x-appointments.status-badge :status="$appointment->status" />
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Actions for the :time appointment of :customer', ['time' => $appointment->starts_at->setTimezone($team->timezone)->format('H:i'), 'customer' => $appointment->customer->name])" data-test="appointment-actions-button" />
                                    <flux:menu>
                                        <flux:menu.item icon="eye" wire:click="openDetail({{ $appointment->id }})" data-test="appointment-view-button">
                                            {{ __('View details') }}
                                        </flux:menu.item>

                                        @if (! $appointment->status->isTerminal())
                                            <flux:menu.item icon="clock" wire:click="openRescheduleModal({{ $appointment->id }})" data-test="appointment-reschedule-button">
                                                {{ __('Reschedule') }}
                                            </flux:menu.item>
                                        @endif

                                        @foreach ($appointment->status->allowedTransitions() as $transition)
                                            @if ($transition === \App\Enums\AppointmentStatus::Cancelled)
                                                <flux:menu.item variant="danger" icon="x-mark" wire:click="openCancelModal({{ $appointment->id }})" data-test="appointment-cancel-button">
                                                    {{ __('Cancel appointment') }}
                                                </flux:menu.item>
                                            @else
                                                <flux:menu.item icon="check" wire:click="transitionStatus({{ $appointment->id }}, '{{ $transition->value }}')" data-test="appointment-transition-button">
                                                    {{ match ($transition) {
                                                        \App\Enums\AppointmentStatus::Confirmed => __('Confirm'),
                                                        \App\Enums\AppointmentStatus::Completed => __('Mark completed'),
                                                        \App\Enums\AppointmentStatus::NoShow => __('Mark as no-show'),
                                                        default => $transition->label(),
                                                    } }}
                                                </flux:menu.item>
                                            @endif
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <flux:pagination :paginator="$this->appointments" class="mt-4" data-test="appointments-pagination" />
        @endif
    </div>

    <flux:error name="status" />

    <flux:modal name="appointment-detail" focusable class="max-w-lg">
        @if ($this->detailAppointment)
            <x-appointments.detail :appointment="$this->detailAppointment" :team="$team" />
        @endif
    </flux:modal>

    <flux:modal name="cancel-appointment" focusable class="max-w-lg">
        <form wire:submit="confirmCancel" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Cancel this appointment?') }}</flux:heading>
                <flux:subheading>
                    @if ($this->cancelAppointment)
                        {{ __(':service with :staff on :time will be cancelled and the slot freed. :customer will be notified by email.', [
                            'service' => $this->cancelAppointment->service->name,
                            'staff' => $this->cancelAppointment->staff->name,
                            'time' => $this->cancelAppointment->starts_at->setTimezone($team->timezone)->isoFormat('MMMM D, YYYY [at] HH:mm'),
                            'customer' => $this->cancelAppointment->customer->name,
                        ]) }}
                    @endif
                </flux:subheading>
            </div>
            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Keep appointment') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="appointment-cancel-confirm">{{ __('Cancel appointment') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="reschedule-appointment" focusable class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Reschedule appointment') }}</flux:heading>
                <flux:subheading>
                    @if ($this->rescheduleAppointment)
                        {{ __(':service with :staff, currently :time', [
                            'service' => $this->rescheduleAppointment->service->name,
                            'staff' => $this->rescheduleAppointment->staff->name,
                            'time' => $this->rescheduleAppointment->starts_at->setTimezone($team->timezone)->isoFormat('MMMM D, YYYY [at] HH:mm'),
                        ]) }}
                    @endif
                </flux:subheading>
            </div>

            <flux:input wire:model.live="rescheduleDate" type="date" :label="__('New day')" data-test="reschedule-date-input" />

            <div role="group" aria-label="{{ __('Available start times') }}">
                @if ($this->rescheduleSlots->isEmpty())
                    <flux:text data-test="reschedule-no-slots">{{ __('No open times on this day. Please pick another day.') }}</flux:text>
                @else
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        @foreach ($this->rescheduleSlots as $slot)
                            <button type="button" wire:click="rescheduleTo('{{ $slot->startsAt->toIso8601String() }}')" data-test="reschedule-slot-button"
                                class="rounded-lg border border-zinc-200 px-3 py-2 text-center font-medium text-zinc-900 transition hover:border-brand-500 hover:bg-brand-50 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700 dark:text-white dark:hover:border-brand-400 dark:hover:bg-zinc-800">
                                {{ $slot->startsAt->setTimezone($team->timezone)->format('H:i') }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <flux:error name="rescheduleSlot" />
            <flux:error name="status" />
        </div>
    </flux:modal>

    @if ($this->canCreate)
        <flux:modal name="new-appointment" focusable class="max-w-lg">
            <form wire:submit="createAppointment" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('New appointment') }}</flux:heading>
                    <flux:subheading>{{ __('Book a slot for a customer. They receive a confirmation email.') }}</flux:subheading>
                </div>

                <div class="space-y-4">
                    <flux:input wire:model="customerName" :label="__('Customer name')" required data-test="new-customer-name" />
                    <flux:input wire:model="customerEmail" type="email" :label="__('Customer email')" required data-test="new-customer-email" />
                    <flux:input wire:model="customerPhone" type="tel" :label="__('Customer phone')" :description="__('Optional')" data-test="new-customer-phone" />

                    <flux:select wire:model.live="newServiceId" :label="__('Service')" data-test="new-service-select">
                        <flux:select.option value="">{{ __('Choose a service') }}</flux:select.option>
                        @foreach ($this->serviceOptions as $service)
                            <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->canManage)
                        <flux:select wire:model.live="newStaffId" :label="__('Staff member')" data-test="new-staff-select">
                            <flux:select.option value="">{{ __('Choose a staff member') }}</flux:select.option>
                            @foreach ($this->newStaffOptions as $staffMember)
                                <flux:select.option value="{{ $staffMember->id }}">{{ $staffMember->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:select disabled :label="__('Staff member')" :description="__('You can only book your own slots.')" data-test="new-staff-locked">
                            <flux:select.option selected>{{ $this->ownStaff?->name ?? __('No staff record') }}</flux:select.option>
                        </flux:select>
                    @endif
                    <flux:error name="newStaffId" />

                    <flux:input wire:model.live="newDate" type="date" :label="__('Day')" data-test="new-date-input" />

                    <div role="group" aria-label="{{ __('Available start times') }}">
                        @if ($this->newSlots->isEmpty())
                            <flux:text data-test="new-no-slots">{{ __('Pick a service, staff member, and day to see open times.') }}</flux:text>
                        @else
                            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                @foreach ($this->newSlots as $slot)
                                    <button type="button" wire:click="selectNewSlot('{{ $slot->startsAt->toIso8601String() }}')" data-test="new-slot-button"
                                        aria-pressed="{{ $newSlot === $slot->startsAt->toIso8601String() ? 'true' : 'false' }}"
                                        @class([
                                            'rounded-lg border px-3 py-2 text-center font-medium transition focus:outline-2 focus:outline-offset-2 focus:outline-brand-600',
                                            'border-brand-600 bg-brand-600 text-white' => $newSlot === $slot->startsAt->toIso8601String(),
                                            'border-zinc-200 text-zinc-900 hover:border-brand-500 hover:bg-brand-50 dark:border-zinc-700 dark:text-white dark:hover:border-brand-400 dark:hover:bg-zinc-800' => $newSlot !== $slot->startsAt->toIso8601String(),
                                        ])>
                                        {{ $slot->startsAt->setTimezone($team->timezone)->format('H:i') }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <flux:error name="newSlot" />

                    <flux:textarea wire:model="newNotes" :label="__('Notes')" :description="__('Optional. Visible to your team and in the confirmation.')" rows="3" data-test="new-notes-input" />
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="new-appointment-save">{{ __('Create appointment') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
