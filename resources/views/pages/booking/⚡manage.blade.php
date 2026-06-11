<?php

use App\Actions\Availability\GetBookableSlots;
use App\Actions\SelfService\CancelAppointmentViaToken;
use App\Actions\SelfService\EnsureWithinCancellationCutoff;
use App\Actions\SelfService\RescheduleAppointmentViaToken;
use App\Data\CurrentTenant;
use App\Data\Slot;
use App\Exceptions\SlotNoLongerAvailableException;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Customer self-service page (FR-CANCEL-1..3, Epic 08): the raw manage
 * token is the only credential (SEC-TOKEN) and resolves exactly one
 * appointment. Cancel and reschedule respect the tenant's cancellation
 * cut-off; past it the actions render disabled with the reason
 * (pages.md §Manage appointment). Every Livewire request re-resolves the
 * appointment by token and re-sets the tenant context (SEC-TENANT).
 */
new #[Layout('layouts::booking')] class extends Component
{
    /** The raw manage token from the URL: never logged (SEC-TOKEN-2). */
    #[Locked]
    public string $token;

    #[Locked]
    public ?string $notice = null;

    public string $rescheduleDate = '';

    /** UTC start instant of the chosen new slot (ISO 8601), set server-side. */
    #[Locked]
    public ?string $selectedSlot = null;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->establishTenantContext();

        $this->rescheduleDate = $this->appointment->starts_at
            ->setTimezone($this->appointment->team->timezone)
            ->format('Y-m-d');
    }

    /**
     * Livewire update requests do not pass through any tenant-resolving
     * middleware, so the appointment is re-resolved by its token and the
     * tenant context re-established before any action runs (SEC-TENANT,
     * ADR-0002); a stale or revoked token 404s here.
     */
    public function hydrate(): void
    {
        $this->establishTenantContext();
    }

    public function rendering(View $view): void
    {
        $view->title(__('Your appointment').' - '.$this->appointment->team->name);
    }

    public function cancel(): void
    {
        $this->throttleMutations();

        app(CancelAppointmentViaToken::class)->handle($this->appointment);

        unset($this->appointment);
        $this->selectedSlot = null;
        $this->notice = __('Your appointment has been cancelled. A confirmation email is on its way.');

        Flux::modal('cancel-appointment')->close();
    }

    public function updatedRescheduleDate(): void
    {
        $this->selectedSlot = null;
        unset($this->rescheduleSlots);
    }

    public function selectSlot(string $startsAt): void
    {
        $slot = $this->rescheduleSlots->first(
            fn (Slot $slot): bool => $slot->startsAt->toIso8601String() === $startsAt,
        );

        if ($slot === null) {
            throw ValidationException::withMessages([
                'selectedSlot' => __('This time is no longer available. Please pick another slot.'),
            ]);
        }

        $this->selectedSlot = $slot->startsAt->toIso8601String();
        $this->resetErrorBag('selectedSlot');
    }

    public function reschedule(): void
    {
        $this->throttleMutations();

        if ($this->selectedSlot === null) {
            throw ValidationException::withMessages([
                'selectedSlot' => __('Please pick a new time first.'),
            ]);
        }

        try {
            app(RescheduleAppointmentViaToken::class)->handle(
                $this->appointment,
                CarbonImmutable::parse($this->selectedSlot)->utc(),
                $this->token,
            );
        } catch (SlotNoLongerAvailableException $exception) {
            $this->selectedSlot = null;
            unset($this->rescheduleSlots);

            throw ValidationException::withMessages([
                'selectedSlot' => $exception->getMessage(),
            ]);
        }

        $this->selectedSlot = null;
        unset($this->appointment, $this->rescheduleSlots);
        $this->notice = __('Your appointment has been moved. A confirmation email with the new time is on its way.');
    }

    /**
     * The appointment behind the token, resolved fresh on every request
     * (scoped reads of service, staff, and customer rely on the tenant
     * context set from it).
     */
    #[Computed]
    public function appointment(): Appointment
    {
        $appointment = Appointment::findByManageToken($this->token);

        abort_if($appointment === null, 404);

        return $appointment;
    }

    #[Computed]
    public function changesClosed(): bool
    {
        return app(EnsureWithinCancellationCutoff::class)->hasClosed($this->appointment);
    }

    #[Computed]
    public function closedReason(): string
    {
        return app(EnsureWithinCancellationCutoff::class)->closedMessage($this->appointment);
    }

    /**
     * Open start times for the chosen day, excluding this appointment's
     * own range so its current time never blocks the move (FR-APPT-3).
     *
     * @return Collection<int, Slot>
     */
    #[Computed]
    public function rescheduleSlots(): Collection
    {
        $appointment = $this->appointment;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->rescheduleDate) !== 1) {
            return collect();
        }

        return app(GetBookableSlots::class)->handle(
            team: $appointment->team,
            service: $appointment->service,
            staff: $appointment->staff,
            fromDate: $this->rescheduleDate,
            untilDate: $this->rescheduleDate,
            excludeAppointmentId: $appointment->id,
        );
    }

    #[Computed]
    public function selectedSlotLabel(): ?string
    {
        return $this->selectedSlot === null
            ? null
            : CarbonImmutable::parse($this->selectedSlot)
                ->setTimezone($this->appointment->team->timezone)
                ->isoFormat('dddd, MMMM D, YYYY [at] HH:mm');
    }

    private function establishTenantContext(): void
    {
        app(CurrentTenant::class)->set($this->appointment->team);
    }

    /**
     * Light throttle for the mutating token actions (SEC-RATE): 20 per
     * minute per IP, with a clear non-leaky message.
     */
    private function throttleMutations(): void
    {
        if (RateLimiter::attempt('manage-actions:'.request()->ip(), 20, fn (): bool => true, 60)) {
            return;
        }

        throw ValidationException::withMessages([
            'notice' => __('Too many requests. Please wait a minute and try again.'),
        ]);
    }
}; ?>

<div>
    <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="xl" level="1">{{ __('Your appointment') }}</flux:heading>
        <flux:text class="mt-1">{{ __('with :team', ['team' => $this->appointment->team->name]) }}</flux:text>

        @if ($notice)
            <flux:callout variant="success" icon="check-circle" class="mt-6" data-test="manage-notice">
                <flux:callout.text>{{ $notice }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <x-booking.appointment-summary :appointment="$this->appointment" :team="$this->appointment->team" />
        </div>

        <flux:error name="notice" />
        <flux:error name="cancel" />
        <flux:error name="cutoff" />
        <flux:error name="status" />

        @if ($this->appointment->status->isTerminal())
            <flux:callout icon="information-circle" class="mt-6" data-test="manage-terminal-note">
                <flux:callout.text>
                    {{ __('This appointment can no longer be changed online.') }}
                    @if ($this->appointment->team->contact_email)
                        {{ __('To book a new time, contact :team at :email or book again online.', ['team' => $this->appointment->team->name, 'email' => $this->appointment->team->contact_email]) }}
                    @endif
                </flux:callout.text>
            </flux:callout>
        @elseif ($this->changesClosed)
            <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:button variant="danger" disabled data-test="manage-cancel-disabled">
                    {{ __('Cancel appointment') }}
                </flux:button>
                <flux:text class="mt-3" data-test="manage-cutoff-reason">{{ $this->closedReason }}</flux:text>
            </div>
        @else
            <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:modal.trigger name="cancel-appointment">
                    <flux:button variant="danger" data-test="manage-cancel-button">
                        {{ __('Cancel appointment') }}
                    </flux:button>
                </flux:modal.trigger>
            </div>

            <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700" data-test="manage-reschedule">
                <flux:heading size="lg" level="2">{{ __('Move to another time') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Times are shown in :timezone time.', ['timezone' => $this->appointment->team->timezone]) }}</flux:text>

                <div class="mt-4 max-w-48">
                    <flux:input wire:model.live="rescheduleDate" type="date" :label="__('New day')" data-test="manage-reschedule-date" />
                </div>

                <div class="mt-4" role="group" aria-label="{{ __('Available start times') }}">
                    @if ($this->rescheduleSlots->isEmpty())
                        <flux:text data-test="manage-no-slots">{{ __('No open times on this day. Please pick another day.') }}</flux:text>
                    @else
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            @foreach ($this->rescheduleSlots as $slot)
                                <button type="button" wire:click="selectSlot('{{ $slot->startsAt->toIso8601String() }}')" data-test="manage-slot-button"
                                    aria-pressed="{{ $selectedSlot === $slot->startsAt->toIso8601String() ? 'true' : 'false' }}"
                                    @class([
                                        'rounded-lg border px-3 py-2 text-center font-medium transition focus:outline-2 focus:outline-offset-2 focus:outline-brand-600',
                                        'border-brand-600 bg-brand-600 text-white' => $selectedSlot === $slot->startsAt->toIso8601String(),
                                        'border-zinc-200 text-zinc-900 hover:border-brand-500 hover:bg-brand-50 dark:border-zinc-700 dark:text-white dark:hover:border-brand-400 dark:hover:bg-zinc-800' => $selectedSlot !== $slot->startsAt->toIso8601String(),
                                    ])>
                                    {{ $slot->startsAt->setTimezone($this->appointment->team->timezone)->format('H:i') }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <flux:error name="selectedSlot" />

                @if ($selectedSlot)
                    <div class="mt-4 flex items-center justify-end gap-4">
                        <flux:text data-test="manage-selected-slot">{{ $this->selectedSlotLabel }}</flux:text>
                        <flux:button variant="primary" wire:click="reschedule" data-test="manage-reschedule-confirm">
                            {{ __('Confirm new time') }}
                        </flux:button>
                    </div>
                @endif
            </div>

            <flux:modal name="cancel-appointment" focusable class="max-w-lg">
                <form wire:submit="cancel" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Cancel this appointment?') }}</flux:heading>
                        <flux:subheading>
                            {{ __(':service with :staff on :time will be cancelled. You will receive a confirmation email.', [
                                'service' => $this->appointment->service->name,
                                'staff' => $this->appointment->staff->name,
                                'time' => $this->appointment->starts_at->setTimezone($this->appointment->team->timezone)->isoFormat('MMMM D, YYYY [at] HH:mm'),
                            ]) }}
                        </flux:subheading>
                    </div>
                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Keep appointment') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit" data-test="manage-cancel-confirm">{{ __('Cancel appointment') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    </section>
</div>
