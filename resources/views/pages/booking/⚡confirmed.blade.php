<?php

use App\Data\CurrentTenant;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Booking confirmation (AC-1): resolved by the raw manage token from the
 * redirect, scoped to the tenant slug in the URL so a token never renders
 * under a foreign tenant's address.
 */
new #[Layout('layouts::booking')] class extends Component
{
    #[Locked]
    public Appointment $appointment;

    #[Locked]
    public string $manageToken;

    public function mount(string $tenant, string $token): void
    {
        $appointment = Appointment::findByManageToken($token);

        abort_if($appointment === null || $appointment->team?->slug !== $tenant, 404);

        app(CurrentTenant::class)->set($appointment->team);

        $this->appointment = $appointment;
        $this->manageToken = $token;
    }

    public function rendering(View $view): void
    {
        $view->title(__('Booking received').' - '.$this->appointment->team->name);
    }

    public function isPendingApproval(): bool
    {
        return $this->appointment->status === AppointmentStatus::Pending;
    }
}; ?>

<div>
    <section class="rounded-xl border border-zinc-200 bg-white p-6 text-center shadow-sm sm:p-8 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:icon.check-circle class="mx-auto size-12 text-lime-600 dark:text-lime-400" aria-hidden="true" />

        <flux:heading size="xl" level="1" class="mt-4">
            {{ $this->isPendingApproval() ? __('Booking request received') : __('Booking confirmed') }}
        </flux:heading>

        <flux:text class="mt-2">
            @if ($this->isPendingApproval())
                {{ __(':team will review your request and confirm it by email.', ['team' => $appointment->team->name]) }}
            @else
                {{ __('You are all set. We look forward to seeing you.') }}
            @endif
        </flux:text>

        <div class="mt-6 border-t border-zinc-200 pt-6 text-left dark:border-zinc-700">
            <x-booking.appointment-summary :appointment="$appointment" :team="$appointment->team" />
        </div>

        <div class="mt-6 rounded-lg bg-zinc-50 p-4 text-left dark:bg-zinc-800">
            <flux:heading size="sm" level="2">{{ __('Manage your appointment') }}</flux:heading>
            <flux:text class="mt-1 text-sm">
                {{ __('Use this personal link to view your appointment. We emailed this link to you.') }}
            </flux:text>
            <a href="{{ route('booking.manage', ['token' => $manageToken]) }}" data-test="manage-appointment-link"
                class="mt-2 block break-all rounded-md text-sm font-medium text-brand-700 underline hover:text-brand-800 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:text-brand-300 dark:hover:text-brand-200">
                {{ route('booking.manage', ['token' => $manageToken]) }}
            </a>
        </div>
    </section>
</div>
