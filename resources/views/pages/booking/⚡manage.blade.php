<?php

use App\Data\CurrentTenant;
use App\Models\Appointment;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * View-only customer self-service page (Epic 06, AC-1): the raw manage
 * token is the only credential (SEC-TOKEN). Cancel and reschedule actions
 * arrive in Epic 08; this page only shows the appointment.
 */
new #[Layout('layouts::booking')] class extends Component
{
    #[Locked]
    public Appointment $appointment;

    public function mount(string $token): void
    {
        $appointment = Appointment::findByManageToken($token);

        abort_if($appointment === null, 404);

        // Scoped reads (service, staff, customer) need the tenant context,
        // which on this tenant-less route comes from the appointment itself.
        app(CurrentTenant::class)->set($appointment->team);

        $this->appointment = $appointment;
    }

    public function rendering(View $view): void
    {
        $view->title(__('Your appointment').' - '.$this->appointment->team->name);
    }
}; ?>

<div>
    <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="xl" level="1">{{ __('Your appointment') }}</flux:heading>
        <flux:text class="mt-1">{{ __('with :team', ['team' => $appointment->team->name]) }}</flux:text>

        <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            <x-booking.appointment-summary :appointment="$appointment" :team="$appointment->team" />
        </div>

        <flux:callout icon="information-circle" class="mt-6" data-test="manage-note">
            <flux:callout.text>
                {{ __('Cancelling or rescheduling your appointment will soon be possible right here on this page. For now, please reach out to the office directly.') }}
                @if ($appointment->team->contact_email)
                    {{ __('You can contact :team at :email.', ['team' => $appointment->team->name, 'email' => $appointment->team->contact_email]) }}
                @endif
            </flux:callout.text>
        </flux:callout>
    </section>
</div>
