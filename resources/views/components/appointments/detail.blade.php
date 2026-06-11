{{--
    Appointment detail body shared by the list and calendar pages
    (pages.md §Appointment detail / edit): status, schedule, and the
    customer's contact information.
--}}
@props([
    'appointment',
    'team',
])

<div class="space-y-6" data-test="appointment-detail">
    <div>
        <flux:heading size="lg">{{ __('Appointment details') }}</flux:heading>
        <flux:subheading>{{ $appointment->service->name }} {{ __('with') }} {{ $appointment->staff->name }}</flux:subheading>
    </div>

    <dl class="space-y-3 text-sm">
        <div class="flex items-center justify-between gap-4">
            <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</dt>
            <dd><x-appointments.status-badge :status="$appointment->status" /></dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt class="text-zinc-600 dark:text-zinc-400">{{ __('When') }}</dt>
            <dd class="text-right font-medium text-zinc-900 dark:text-white">
                {{ $appointment->starts_at->setTimezone($team->timezone)->isoFormat('dddd, MMMM D, YYYY') }}
                <span class="block">
                    {{ $appointment->starts_at->setTimezone($team->timezone)->format('H:i') }}
                    {{ __('to') }}
                    {{ $appointment->ends_at->setTimezone($team->timezone)->format('H:i') }}
                </span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $team->timezone }}</span>
            </dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Customer') }}</dt>
            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $appointment->customer->name }}</dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Email') }}</dt>
            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $appointment->customer->email }}</dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Phone') }}</dt>
            <dd class="text-right font-medium text-zinc-900 dark:text-white">{{ $appointment->customer->phone ?? __('Not provided') }}</dd>
        </div>
        @if ($appointment->notes)
            <div>
                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Notes') }}</dt>
                <dd class="mt-1 whitespace-pre-line font-medium text-zinc-900 dark:text-white">{{ $appointment->notes }}</dd>
            </div>
        @endif
    </dl>
</div>
