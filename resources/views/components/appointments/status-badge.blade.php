@props([
    'status',
])

@php
    $statusColor = match ($status) {
        \App\Enums\AppointmentStatus::Pending => 'amber',
        \App\Enums\AppointmentStatus::Confirmed => 'lime',
        \App\Enums\AppointmentStatus::Completed => 'zinc',
        \App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow => 'red',
    };
@endphp

{{-- Status is always label + color, never color alone (NFR-A11Y-3). --}}
<flux:badge size="sm" :color="$statusColor" data-test="appointment-status-badge">{{ $status->label() }}</flux:badge>
