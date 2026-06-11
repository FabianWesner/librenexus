<?php

use App\Actions\Availability\GetBookableSlots;
use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Data\Slot;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Mail\AppointmentConfirmationMail;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The public booking flow (FR-BOOK-1..7): service, staff, slot, details,
 * confirm. Everything is validated server-side and the final slot check
 * plus the exclusion constraint live in BookAppointment (AC-3, AC-4).
 */
new #[Layout('layouts::booking')] class extends Component
{
    #[Locked]
    public Team $team;

    #[Locked]
    public int $step = 1;

    #[Locked]
    public ?int $serviceId = null;

    /** Either 'any' or a staff id as string (FR-BOOK-2). */
    #[Locked]
    public ?string $staffSelection = null;

    /** Local dates (tenant timezone) with at least one open slot. @var list<string> */
    #[Locked]
    public array $availableDates = [];

    #[Locked]
    public ?string $selectedDate = null;

    /** UTC start instant of the chosen slot (ISO 8601). */
    #[Locked]
    public ?string $selectedSlot = null;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    /** Honeypot (SEC-RATE-2): humans never see or fill this field. */
    public string $website = '';

    public function mount(string $tenant): void
    {
        $this->team = Team::query()->where('slug', $tenant)->firstOrFail();

        app(CurrentTenant::class)->set($this->team);
    }

    /**
     * Livewire update requests do not pass through ResolvePublicTenant, so
     * the tenant context is re-established from the locked team property
     * before any action runs (SEC-TENANT, ADR-0002).
     */
    public function hydrate(): void
    {
        app(CurrentTenant::class)->set($this->team);
    }

    public function rendering(View $view): void
    {
        $view->title(__('Book an appointment').' - '.$this->team->name);
    }

    public function chooseService(int $serviceId): void
    {
        $service = Service::query()->bookable()->findOrFail($serviceId);

        $this->serviceId = $service->id;
        $this->staffSelection = null;
        $this->resetSlotState();
        $this->resetErrorBag();
        $this->step = 2;
    }

    public function chooseStaff(string $selection): void
    {
        $this->throttleStepAction();

        $service = $this->requireService();

        if ($selection === 'any') {
            $this->staffSelection = 'any';
        } else {
            $staffMember = $service->staff()->bookable()->findOrFail((int) $selection);
            $this->staffSelection = (string) $staffMember->id;
        }

        $this->refreshAvailableDates();
        $this->resetErrorBag();
        $this->step = 3;
    }

    public function selectDate(string $date): void
    {
        $this->throttleStepAction();

        if (! in_array($date, $this->availableDates, true)) {
            throw ValidationException::withMessages([
                'selectedDate' => __('Please pick one of the available days.'),
            ]);
        }

        $this->selectedDate = $date;
        $this->selectedSlot = null;
        unset($this->timeSlots);
    }

    public function chooseSlot(string $startsAt): void
    {
        $slot = $this->timeSlots->first(
            fn (Slot $slot): bool => $slot->startsAt->toIso8601String() === $startsAt,
        );

        if ($slot === null) {
            throw ValidationException::withMessages([
                'selectedSlot' => __('This time is no longer available. Please pick another slot.'),
            ]);
        }

        $this->selectedSlot = $slot->startsAt->toIso8601String();
        $this->resetErrorBag();
        $this->step = 4;
    }

    public function submitDetails(): void
    {
        $this->validate($this->detailRules());

        $this->step = 5;
    }

    public function back(): void
    {
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function confirmBooking(): void
    {
        // Honeypot (SEC-RATE-2): a filled hidden field means a bot. Drop
        // the submission silently; never reveal why nothing happened.
        if (filled($this->website)) {
            return;
        }

        $validated = $this->validate($this->detailRules());
        $startsAt = $this->validatedSlotStart();

        // Throttle before any work happens (SEC-RATE-1, non-leaky message).
        if (! RateLimiter::attempt('booking:'.request()->ip(), 10, fn (): bool => true, 60)) {
            throw ValidationException::withMessages([
                'booking' => __('Too many booking attempts. Please wait a minute and try again.'),
            ]);
        }

        try {
            $booked = app(BookAppointment::class)->handle($this->team, new BookingRequest(
                serviceId: $this->requireService()->id,
                staffId: $this->staffSelection === 'any' ? null : (int) $this->staffSelection,
                startsAt: $startsAt,
                customerName: $validated['name'],
                customerEmail: $validated['email'],
                customerPhone: $validated['phone'] !== '' ? $validated['phone'] : null,
                notes: $validated['notes'] !== '' ? $validated['notes'] : null,
            ));
        } catch (SlotNoLongerAvailableException $exception) {
            $this->refreshAvailableDates();
            $this->step = 3;

            throw ValidationException::withMessages([
                'selectedSlot' => $exception->getMessage(),
            ]);
        }

        Mail::to($booked->appointment->customer->email)->queue(
            new AppointmentConfirmationMail($booked->appointment, $booked->rawManageToken),
        );

        $this->redirectRoute('booking.confirmed', [
            'tenant' => $this->team->slug,
            'token' => $booked->rawManageToken,
        ]);
    }

    /**
     * @return EloquentCollection<int, Service>
     */
    #[Computed]
    public function services(): EloquentCollection
    {
        return Service::query()->bookable()->orderBy('name')->get();
    }

    #[Computed]
    public function service(): ?Service
    {
        return $this->serviceId === null
            ? null
            : Service::query()->bookable()->find($this->serviceId);
    }

    /**
     * @return EloquentCollection<int, Staff>
     */
    #[Computed]
    public function staffOptions(): EloquentCollection
    {
        return $this->service?->staff()->bookable()->orderBy('name')->get()
            ?? new EloquentCollection;
    }

    /**
     * The open start times for the selected day, deduplicated across staff
     * for "any available" (AC-2). One engine day per request keeps this
     * cheap; the booking action re-validates anyway.
     *
     * @return Collection<int, Slot>
     */
    #[Computed]
    public function timeSlots(): Collection
    {
        if ($this->service === null || $this->selectedDate === null) {
            return collect();
        }

        return app(GetBookableSlots::class)
            ->handle($this->team, $this->service, $this->chosenStaff(), $this->selectedDate, $this->selectedDate)
            ->unique(fn (Slot $slot): int => $slot->startsAt->getTimestamp())
            ->values();
    }

    /**
     * Every day of the booking horizon for the date picker; days without
     * slots render disabled (pages.md §Booking page).
     *
     * @return list<array{date: string, available: bool}>
     */
    #[Computed]
    public function dayOptions(): array
    {
        $today = CarbonImmutable::now($this->team->timezone);
        $days = [];

        for ($offset = 0; $offset <= $this->team->booking_horizon_days; $offset++) {
            $date = $today->addDays($offset)->format('Y-m-d');

            $days[] = [
                'date' => $date,
                'available' => in_array($date, $this->availableDates, true),
            ];
        }

        return $days;
    }

    #[Computed]
    public function selectedSlotLabel(): ?string
    {
        return $this->selectedSlot === null
            ? null
            : CarbonImmutable::parse($this->selectedSlot)
                ->setTimezone($this->team->timezone)
                ->isoFormat('dddd, MMMM D, YYYY [at] HH:mm');
    }

    #[Computed]
    public function staffLabel(): ?string
    {
        if ($this->staffSelection === null) {
            return null;
        }

        return $this->staffSelection === 'any'
            ? __('Any available staff member')
            : $this->chosenStaff()?->name;
    }

    /**
     * Recompute which days of the horizon still have open slots. One
     * engine pass when entering the slot step or after a lost race; the
     * per-day slot list stays a single light query per request.
     */
    private function refreshAvailableDates(): void
    {
        $service = $this->requireService();
        $timezone = $this->team->timezone;
        $today = CarbonImmutable::now($timezone);

        $this->availableDates = app(GetBookableSlots::class)
            ->handle(
                $this->team,
                $service,
                $this->chosenStaff(),
                $today->format('Y-m-d'),
                $today->addDays($this->team->booking_horizon_days)->format('Y-m-d'),
            )
            ->map(fn (Slot $slot): string => $slot->startsAt->setTimezone($timezone)->format('Y-m-d'))
            ->unique()
            ->values()
            ->all();

        $this->selectedDate = $this->availableDates[0] ?? null;
        $this->selectedSlot = null;
        unset($this->timeSlots, $this->dayOptions);
    }

    /**
     * Throttle the step actions that trigger slot computation (entering
     * the time step, changing the day) per IP (SEC-RATE, Epic 10): the
     * engine pass is the most expensive unauthenticated code path. The
     * final confirm keeps its own, much tighter limit.
     */
    private function throttleStepAction(): void
    {
        if (! RateLimiter::attempt('booking-steps:'.request()->ip(), 60, fn (): bool => true, 60)) {
            throw ValidationException::withMessages([
                'booking' => __('Too many requests. Please wait a minute and try again.'),
            ]);
        }
    }

    private function chosenStaff(): ?Staff
    {
        if ($this->staffSelection === null || $this->staffSelection === 'any') {
            return null;
        }

        return Staff::query()->bookable()->find((int) $this->staffSelection);
    }

    private function requireService(): Service
    {
        $service = $this->service;

        if ($service === null) {
            throw ValidationException::withMessages([
                'serviceId' => __('Please choose a service first.'),
            ]);
        }

        return $service;
    }

    private function validatedSlotStart(): CarbonImmutable
    {
        if ($this->selectedSlot === null) {
            throw ValidationException::withMessages([
                'selectedSlot' => __('Please pick a time slot first.'),
            ]);
        }

        $startsAt = CarbonImmutable::parse($this->selectedSlot)->utc();

        if ($startsAt->isPast()) {
            throw ValidationException::withMessages([
                'selectedSlot' => __('This time has already passed. Please pick another slot.'),
            ]);
        }

        return $startsAt;
    }

    /**
     * @return array<string, list<string>>
     */
    private function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function resetSlotState(): void
    {
        $this->availableDates = [];
        $this->selectedDate = null;
        $this->selectedSlot = null;
        unset($this->timeSlots, $this->dayOptions);
    }
}; ?>

<div>
    <header class="mb-8 text-center">
        <flux:heading size="xl" level="1">{{ $team->name }}</flux:heading>
        @if ($team->contact_email)
            <flux:text class="mt-1">
                {{ __('Questions? Contact') }}
                <a href="mailto:{{ $team->contact_email }}" class="rounded-md font-medium text-brand-700 underline hover:text-brand-800 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-brand-300 dark:hover:text-brand-200">{{ $team->contact_email }}</a>
            </flux:text>
        @endif
    </header>

    <nav aria-label="{{ __('Booking progress') }}" class="mb-8">
        <ol class="flex items-center justify-center gap-2 text-sm">
            @foreach ([1 => __('Service'), 2 => __('Staff'), 3 => __('Time'), 4 => __('Details'), 5 => __('Confirm')] as $number => $label)
                <li @if ($number === $step) aria-current="step" @endif class="flex items-center gap-2">
                    <span @class([
                        'flex size-7 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-brand-600 text-white' => $number === $step,
                        'bg-brand-100 text-brand-800 dark:bg-brand-950 dark:text-brand-300' => $number < $step,
                        'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => $number > $step,
                    ])>{{ $number }}</span>
                    <span class="{{ $number === $step ? 'font-medium text-zinc-900 dark:text-white' : 'sr-only text-zinc-500 sm:not-sr-only dark:text-zinc-400' }}">{{ $label }}</span>
                </li>
            @endforeach
        </ol>
    </nav>

    <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8 dark:border-zinc-700 dark:bg-zinc-900" aria-live="polite">
        @if ($step === 1)
            <flux:heading size="lg" level="2">{{ __('Choose a service') }}</flux:heading>

            @if ($this->services->isEmpty())
                <flux:text class="mt-4" data-test="booking-empty-state">
                    {{ __('Online booking is not available yet. Please check back later.') }}
                </flux:text>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($this->services as $service)
                        <li>
                            <button type="button" wire:click="chooseService({{ $service->id }})" data-test="booking-service-button"
                                class="w-full rounded-lg border border-zinc-200 px-4 py-4 text-left transition hover:border-brand-500 hover:bg-brand-50 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700 dark:hover:border-brand-400 dark:hover:bg-zinc-800">
                                <span class="block font-medium text-zinc-900 dark:text-white">{{ $service->name }}</span>
                                <span class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ __(':minutes minutes', ['minutes' => $service->duration_minutes]) }}
                                    @if ($service->formattedPrice($team->currency))
                                        &middot; {{ $service->formattedPrice($team->currency) }}
                                    @endif
                                </span>
                                @if ($service->description)
                                    <span class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">{{ $service->description }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @elseif ($step === 2)
            <flux:heading size="lg" level="2">{{ __('Who should it be with?') }}</flux:heading>

            <ul class="mt-4 space-y-3">
                <li>
                    <button type="button" wire:click="chooseStaff('any')" data-test="booking-staff-any-button"
                        class="w-full rounded-lg border border-zinc-200 px-4 py-4 text-left transition hover:border-brand-500 hover:bg-brand-50 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700 dark:hover:border-brand-400 dark:hover:bg-zinc-800">
                        <span class="block font-medium text-zinc-900 dark:text-white">{{ __('Any available') }}</span>
                        <span class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">{{ __('We pick a free staff member for you.') }}</span>
                    </button>
                </li>
                @foreach ($this->staffOptions as $staffMember)
                    <li>
                        <button type="button" wire:click="chooseStaff('{{ $staffMember->id }}')" data-test="booking-staff-button"
                            class="w-full rounded-lg border border-zinc-200 px-4 py-4 text-left font-medium text-zinc-900 transition hover:border-brand-500 hover:bg-brand-50 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700 dark:text-white dark:hover:border-brand-400 dark:hover:bg-zinc-800">
                            {{ $staffMember->name }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <flux:error name="serviceId" />
            <flux:error name="booking" />
        @elseif ($step === 3)
            <flux:heading size="lg" level="2">{{ __('Pick a day and time') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Times are shown in :timezone time.', ['timezone' => $team->timezone]) }}</flux:text>

            @if (empty($this->availableDates))
                <flux:text class="mt-4" data-test="booking-no-slots">
                    {{ __('There are no open time slots right now. Please check back later.') }}
                </flux:text>
            @else
                <div class="mt-4" role="group" aria-label="{{ __('Available days') }}">
                    <div class="flex gap-2 overflow-x-auto pb-2">
                        @foreach ($this->dayOptions as $day)
                            @php($dayDate = \Carbon\CarbonImmutable::parse($day['date']))
                            <button type="button" wire:click="selectDate('{{ $day['date'] }}')" @disabled(! $day['available'])
                                aria-pressed="{{ $selectedDate === $day['date'] ? 'true' : 'false' }}"
                                data-test="booking-day-button"
                                @class([
                                    'flex min-w-16 flex-col items-center rounded-lg border px-3 py-2 text-sm transition focus:outline-2 focus:outline-offset-2 focus:outline-brand-600',
                                    'border-brand-600 bg-brand-600 text-white' => $selectedDate === $day['date'],
                                    'border-zinc-200 text-zinc-900 hover:border-brand-500 dark:border-zinc-700 dark:text-white' => $selectedDate !== $day['date'] && $day['available'],
                                    'cursor-not-allowed border-zinc-100 text-zinc-400 dark:border-zinc-800 dark:text-zinc-600' => ! $day['available'],
                                ])>
                                <span class="text-xs uppercase">{{ $dayDate->isoFormat('ddd') }}</span>
                                <span class="font-semibold">{{ $dayDate->isoFormat('D') }}</span>
                                <span class="text-xs">{{ $dayDate->isoFormat('MMM') }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-6" role="group" aria-label="{{ __('Available start times') }}">
                    @if ($this->timeSlots->isEmpty())
                        <flux:text data-test="booking-no-slots">{{ __('No open times on this day. Please pick another day.') }}</flux:text>
                    @else
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            @foreach ($this->timeSlots as $slot)
                                <button type="button" wire:click="chooseSlot('{{ $slot->startsAt->toIso8601String() }}')" data-test="booking-slot-button"
                                    class="rounded-lg border border-zinc-200 px-3 py-3 text-center font-medium text-zinc-900 transition hover:border-brand-500 hover:bg-brand-50 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700 dark:text-white dark:hover:border-brand-400 dark:hover:bg-zinc-800">
                                    {{ $slot->startsAt->setTimezone($team->timezone)->format('H:i') }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <flux:error name="selectedDate" />
            <flux:error name="selectedSlot" />
            <flux:error name="booking" />
        @elseif ($step === 4)
            <flux:heading size="lg" level="2">{{ __('Your details') }}</flux:heading>

            <form wire:submit="submitDetails" class="mt-4 space-y-4">
                <flux:input wire:model="name" :label="__('Name')" required autocomplete="name" data-test="booking-name-input" />
                <flux:input wire:model="email" type="email" :label="__('Email')" required autocomplete="email" data-test="booking-email-input" />
                <flux:input wire:model="phone" type="tel" :label="__('Phone')" :description="__('Optional')" autocomplete="tel" data-test="booking-phone-input" />
                <flux:textarea wire:model="notes" :label="__('Notes')" :description="__('Optional. Anything we should know beforehand?')" rows="3" data-test="booking-notes-input" />

                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit" data-test="booking-details-continue">{{ __('Continue') }}</flux:button>
                </div>
            </form>
        @else
            <flux:heading size="lg" level="2">{{ __('Confirm your booking') }}</flux:heading>

            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Service') }}</dt>
                    <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $this->service?->name }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Staff') }}</dt>
                    <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $this->staffLabel }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('When') }}</dt>
                    <dd class="text-right font-medium text-zinc-900 dark:text-white">
                        {{ $this->selectedSlotLabel }}
                        <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $team->timezone }}</span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Name') }}</dt>
                    <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $name }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Email') }}</dt>
                    <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $email }}</dd>
                </div>
            </dl>

            <form wire:submit="confirmBooking" class="mt-6">
                <div class="sr-only" aria-hidden="true">
                    <label for="booking-website-field">{{ __('Website') }}</label>
                    <input id="booking-website-field" type="text" wire:model="website" name="website" tabindex="-1" autocomplete="off" />
                </div>

                <flux:error name="selectedSlot" />
                <flux:error name="booking" />

                <div class="mt-2 flex justify-end">
                    <flux:button variant="primary" type="submit" data-test="booking-confirm-button">{{ __('Confirm booking') }}</flux:button>
                </div>
            </form>
        @endif

        @if ($step > 1)
            <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <flux:button variant="ghost" size="sm" icon="arrow-left" wire:click="back" data-test="booking-back-button">
                    {{ __('Back') }}
                </flux:button>
            </div>
        @endif
    </section>
</div>
