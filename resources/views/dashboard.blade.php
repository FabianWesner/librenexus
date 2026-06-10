<x-layouts::app :title="__('Dashboard')">
    <livewire:pages::teams.pending-invitations-modal />

    @php
        /** @var \App\Models\Team|null $currentTenant */
        $currentTenant = app(\App\Data\CurrentTenant::class)->get();
        $needsStaffLink = $currentTenant !== null
            && auth()->user()->teamRole($currentTenant) === \App\Enums\TeamRole::Staff
            && auth()->user()->staffRecordFor($currentTenant) === null;
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        @if ($needsStaffLink)
            <flux:callout icon="link" data-test="staff-link-notice">
                <flux:callout.heading>{{ __('Your account is not linked to a staff profile yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('A team admin needs to link your account to a staff record before you can take bookings. Until then you have no bookable availability and no appointments.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
        </div>
        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
        </div>
    </div>
</x-layouts::app>
