@props([
    'appointment',
    'team',
])

@php
    $statusColor = match ($appointment->status) {
        \App\Enums\AppointmentStatus::Pending => 'amber',
        \App\Enums\AppointmentStatus::Confirmed => 'lime',
        \App\Enums\AppointmentStatus::Completed => 'zinc',
        \App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow => 'red',
    };
    $statusLabel = $appointment->status === \App\Enums\AppointmentStatus::Pending
        ? __('Pending approval')
        : $appointment->status->label();
@endphp

<dl class="space-y-3 text-sm" data-test="appointment-summary">
    <div class="flex items-center justify-between gap-4">
        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</dt>
        <dd>
            <flux:badge size="sm" :color="$statusColor" data-test="appointment-status-badge">{{ $statusLabel }}</flux:badge>
        </dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Service') }}</dt>
        <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $appointment->service->name }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Staff') }}</dt>
        <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $appointment->staff->name }}</dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('When') }}</dt>
        <dd class="text-right font-medium text-zinc-900 dark:text-white">
            {{ $appointment->starts_at->setTimezone($team->timezone)->isoFormat('dddd, MMMM D, YYYY [at] HH:mm') }}
            <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $team->timezone }}</span>
        </dd>
    </div>
    <div class="flex justify-between gap-4">
        <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Booked for') }}</dt>
        <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $appointment->customer->name }}</dd>
    </div>
</dl>
