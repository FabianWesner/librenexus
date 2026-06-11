<?php

use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tenant landing dashboard (Epic 09): tenant-scoped metrics built from
 * aggregate queries, never per-row loops (FR-DASH-1, NFR-PERF), and a
 * guided onboarding checklist that replaces the metrics until staff,
 * service, and availability exist (FR-DASH-2). All counts and lists rely
 * on the tenant global scope, so figures can never leak across tenants.
 */
new #[Title('Dashboard')] class extends Component
{
    /** The look-ahead window for the upcoming count and per-staff load. */
    private const int UPCOMING_DAYS = 7;

    private const int RECENT_BOOKINGS = 5;

    #[Locked]
    public Team $team;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team->refresh();
    }

    #[Computed]
    public function hasStaff(): bool
    {
        return Staff::query()->exists();
    }

    #[Computed]
    public function hasService(): bool
    {
        return Service::query()->exists();
    }

    #[Computed]
    public function hasAvailability(): bool
    {
        return AvailabilityRule::query()->exists();
    }

    /**
     * Setup is complete once the tenant can actually take a booking:
     * at least one staff member, one service, and one availability rule
     * (FR-DASH-2, AC-2).
     */
    #[Computed]
    public function setupComplete(): bool
    {
        return $this->hasStaff && $this->hasService && $this->hasAvailability;
    }

    /**
     * The guided checklist: one clear next step at a time. The share-link
     * step is always available as the final step (FR-DASH-2).
     *
     * @return list<array{key: string, label: string, description: string, done: bool, url: string|null, action: string|null}>
     */
    #[Computed]
    public function onboardingSteps(): array
    {
        $firstStaffId = Staff::query()->orderBy('id')->value('id');

        return [
            [
                'key' => 'staff',
                'label' => __('Add a staff member'),
                'description' => __('Create the people customers can book.'),
                'done' => $this->hasStaff,
                'url' => route('staff.index', ['current_team' => $this->team->slug]),
                'action' => __('Go to staff'),
            ],
            [
                'key' => 'service',
                'label' => __('Add a service'),
                'description' => __('Define what customers can book, with duration and price.'),
                'done' => $this->hasService,
                'url' => route('services.index', ['current_team' => $this->team->slug]),
                'action' => __('Go to services'),
            ],
            [
                'key' => 'availability',
                'label' => __('Set availability'),
                'description' => __('Add weekly working hours so open slots can be offered.'),
                'done' => $this->hasAvailability,
                'url' => $firstStaffId === null
                    ? route('staff.index', ['current_team' => $this->team->slug])
                    : route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $firstStaffId]),
                'action' => __('Set working hours'),
            ],
            [
                'key' => 'share',
                'label' => __('Share your booking link'),
                'description' => __('Customers book themselves on your public page.'),
                'done' => false,
                'url' => null,
                'action' => null,
            ],
        ];
    }

    /**
     * The first unfinished step: the one highlighted next action (AC-2).
     */
    #[Computed]
    public function currentStepKey(): string
    {
        foreach ($this->onboardingSteps as $step) {
            if (! $step['done']) {
                return $step['key'];
            }
        }

        return 'share';
    }

    /**
     * The public booking page of this tenant (FR-BOOK-1).
     */
    #[Computed]
    public function bookingUrl(): string
    {
        return route('booking.show', ['tenant' => $this->team->slug]);
    }

    /**
     * Appointments today (tenant-timezone day window) that reserve staff
     * time, as one aggregate count (FR-DASH-1, AC-1).
     */
    #[Computed]
    public function todayCount(): int
    {
        [$start, $end] = $this->todayWindow();

        return Appointment::query()
            ->reservingTime()
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->count();
    }

    /**
     * Reserving appointments in the next seven days, as one count.
     */
    #[Computed]
    public function upcomingCount(): int
    {
        $now = CarbonImmutable::now();

        return Appointment::query()
            ->reservingTime()
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<', $now->addDays(self::UPCOMING_DAYS))
            ->count();
    }

    /**
     * Today's schedule with relations eager loaded (no N+1, AC-1).
     *
     * @return EloquentCollection<int, Appointment>
     */
    #[Computed]
    public function todayAppointments(): EloquentCollection
    {
        [$start, $end] = $this->todayWindow();

        return Appointment::query()
            ->with(['staff', 'service', 'customer'])
            ->reservingTime()
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The five most recently created bookings, any status.
     *
     * @return EloquentCollection<int, Appointment>
     */
    #[Computed]
    public function recentBookings(): EloquentCollection
    {
        return Appointment::query()
            ->with(['customer', 'service'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_BOOKINGS)
            ->get();
    }

    /**
     * Reserving appointments per bookable staff member over the next seven
     * days: one group-by aggregate plus the staff list, then merged in
     * memory (FR-DASH-1, NFR-PERF). Rendered as labeled bars, the count is
     * always shown as text so color is never the only signal (NFR-A11Y-3).
     *
     * @return list<array{id: int, name: string, colorHex: string, count: int, percent: int}>
     */
    #[Computed]
    public function staffLoad(): array
    {
        $now = CarbonImmutable::now();

        $counts = Appointment::query()
            ->reservingTime()
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<', $now->addDays(self::UPCOMING_DAYS))
            ->groupBy('staff_id')
            ->selectRaw('staff_id, count(*) as total')
            ->pluck('total', 'staff_id');

        $busiest = max((int) $counts->max(), 1);

        return Staff::query()
            ->bookable()
            ->orderBy('name')
            ->get()
            ->map(fn (Staff $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'colorHex' => $member->color->hex(),
                'count' => (int) $counts->get($member->id, 0),
                'percent' => (int) round((int) $counts->get($member->id, 0) / $busiest * 100),
            ])
            ->all();
    }

    /**
     * A staff-role member whose membership has no staff record cannot take
     * bookings yet; an admin has to link them (Epic 04 AC-7).
     */
    #[Computed]
    public function needsStaffLink(): bool
    {
        return Auth::user()->teamRole($this->team) === TeamRole::Staff
            && Auth::user()->staffRecordFor($this->team) === null;
    }

    /**
     * Today's local day expressed as a UTC range (NFR-I18N: appointments
     * are stored in UTC, the day boundary is the tenant's).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function todayWindow(): array
    {
        $start = CarbonImmutable::now($this->team->timezone)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }
}; ?>

<section class="w-full">
    <livewire:pages::teams.pending-invitations-modal />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __(':team, all times in :timezone', ['team' => $team->name, 'timezone' => $team->timezone]) }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2" data-test="quick-links">
            <flux:button size="sm" icon="plus" :href="route('appointments.index', ['current_team' => $team->slug])" wire:navigate>
                {{ __('New appointment') }}
            </flux:button>
            <flux:button size="sm" icon="users" :href="route('staff.index', ['current_team' => $team->slug])" wire:navigate>
                {{ __('Staff') }}
            </flux:button>
            <flux:button size="sm" icon="briefcase" :href="route('services.index', ['current_team' => $team->slug])" wire:navigate>
                {{ __('Services') }}
            </flux:button>
            <flux:button size="sm" icon="globe-alt" :href="$this->bookingUrl" data-test="booking-page-link">
                {{ __('Booking page') }}
            </flux:button>
        </div>
    </div>

    @if ($this->needsStaffLink)
        <flux:callout icon="link" class="mt-6" data-test="staff-link-notice">
            <flux:callout.heading>{{ __('Your account is not linked to a staff profile yet') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('A team admin needs to link your account to a staff record before you can take bookings. Until then you have no bookable availability and no appointments.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    @if (! $this->setupComplete)
        <div class="mx-auto mt-8 w-full max-w-2xl rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" data-test="onboarding-checklist">
            <flux:heading size="lg">{{ __('Set up your booking page') }}</flux:heading>
            <flux:text class="mt-1">{{ __('A few steps and customers can book you online.') }}</flux:text>

            <ol class="mt-6 space-y-3">
                @foreach ($this->onboardingSteps as $step)
                    @php
                        $state = $step['done'] ? 'done' : ($step['key'] === $this->currentStepKey ? 'current' : 'todo');
                    @endphp
                    <li
                        wire:key="onboarding-{{ $step['key'] }}"
                        data-test="onboarding-step-{{ $step['key'] }}"
                        data-state="{{ $state }}"
                        @class([
                            'flex items-start gap-3 rounded-lg border p-4',
                            'border-brand-300 bg-brand-50 dark:border-brand-500/40 dark:bg-brand-500/10' => $state === 'current',
                            'border-zinc-200 dark:border-zinc-700' => $state !== 'current',
                        ])
                    >
                        @if ($step['done'])
                            <flux:icon.check-circle variant="solid" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        @else
                            <span aria-hidden="true" @class([
                                'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                                'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' => $state === 'current',
                                'border-zinc-300 text-zinc-500 dark:border-zinc-600 dark:text-zinc-400' => $state === 'todo',
                            ])>{{ $loop->iteration }}</span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p @class([
                                'font-medium',
                                'text-zinc-400 line-through dark:text-zinc-500' => $step['done'],
                                'text-zinc-900 dark:text-white' => ! $step['done'],
                            ])>
                                {{ $step['label'] }}
                                @if ($step['done'])
                                    <span class="sr-only">{{ __('(done)') }}</span>
                                @endif
                            </p>

                            @unless ($step['done'])
                                {{-- Explicit zinc-600: the default muted text fails AA on the highlighted brand background. --}}
                                <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $step['description'] }}</p>
                            @endunless

                            @if ($step['key'] === 'share')
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-2"
                                    x-data="{ copied: false, copy() { navigator.clipboard?.writeText($refs.bookingUrl.value).catch(() => {}); this.copied = true; setTimeout(() => this.copied = false, 2000); } }"
                                >
                                    <label for="booking-url" class="sr-only">{{ __('Your public booking link') }}</label>
                                    <input
                                        id="booking-url"
                                        x-ref="bookingUrl"
                                        type="text"
                                        readonly
                                        value="{{ $this->bookingUrl }}"
                                        data-test="booking-url"
                                        class="min-w-0 flex-1 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
                                    />
                                    <flux:button size="sm" icon="clipboard-document" x-on:click="copy" data-test="copy-booking-link">
                                        <span x-show="! copied">{{ __('Copy link') }}</span>
                                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                    </flux:button>
                                    <span class="sr-only" aria-live="polite" x-text="copied ? @js(__('Booking link copied to clipboard')) : ''"></span>
                                </div>
                            @elseif ($state === 'current')
                                <flux:button size="sm" variant="primary" class="mt-3" :href="$step['url']" wire:navigate data-test="onboarding-step-action">
                                    {{ $step['action'] }}
                                </flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    @else
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-test="dashboard-metrics">
            <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" data-test="metric-today">
                <flux:text>{{ __("Today's appointments") }}</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">{{ $this->todayCount }}</flux:heading>
            </div>

            <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" data-test="metric-upcoming">
                <flux:text>{{ __('Upcoming (next 7 days)') }}</flux:text>
                <flux:heading size="xl" class="mt-2 tabular-nums">{{ $this->upcomingCount }}</flux:heading>
            </div>

            <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700 sm:col-span-2 lg:col-span-1" data-test="staff-load">
                <flux:text>{{ __('Per-staff load (next 7 days)') }}</flux:text>
                <ul class="mt-3 space-y-3">
                    @forelse ($this->staffLoad as $load)
                        <li wire:key="staff-load-{{ $load['id'] }}" data-test="staff-load-row">
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $load['colorHex'] }}" aria-hidden="true"></span>
                                    <span class="truncate">{{ $load['name'] }}</span>
                                </span>
                                <span class="tabular-nums font-medium">{{ trans_choice(':count appointment|:count appointments', $load['count'], ['count' => $load['count']]) }}</span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" aria-hidden="true">
                                <div class="h-full rounded-full" style="width: {{ $load['percent'] }}%; background-color: {{ $load['colorHex'] }}"></div>
                            </div>
                        </li>
                    @empty
                        <li><flux:text size="sm">{{ __('No bookable staff yet.') }}</flux:text></li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" data-test="today-list">
                <flux:heading size="lg">{{ __('Today') }}</flux:heading>

                @if ($this->todayAppointments->isEmpty())
                    <flux:text class="mt-3" data-test="today-empty">{{ __('No appointments today. Enjoy the quiet, or share your booking link.') }}</flux:text>
                @else
                    <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach ($this->todayAppointments as $appointment)
                            <li wire:key="today-{{ $appointment->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3" data-test="today-row">
                                <span class="font-medium tabular-nums">{{ $appointment->starts_at->setTimezone($team->timezone)->format('H:i') }}</span>
                                <span class="min-w-0 flex-1">
                                    {{ $appointment->customer->name }}
                                    <span class="text-zinc-500 dark:text-zinc-400">&middot; {{ $appointment->service->name }}</span>
                                </span>
                                <span class="inline-flex items-center gap-2 text-sm">
                                    <span class="size-3 rounded-full" style="background-color: {{ $appointment->staff->color->hex() }}" aria-hidden="true"></span>
                                    {{ $appointment->staff->name }}
                                </span>
                                <x-appointments.status-badge :status="$appointment->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700" data-test="recent-bookings">
                <flux:heading size="lg">{{ __('Recent bookings') }}</flux:heading>

                @if ($this->recentBookings->isEmpty())
                    <flux:text class="mt-3" data-test="recent-empty">{{ __('No bookings yet. Share your booking link to get started.') }}</flux:text>
                @else
                    <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach ($this->recentBookings as $booking)
                            <li wire:key="recent-{{ $booking->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-3" data-test="recent-row">
                                <span class="min-w-0 flex-1">
                                    {{ $booking->customer->name }}
                                    <span class="text-zinc-500 dark:text-zinc-400">&middot; {{ $booking->service->name }}</span>
                                </span>
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $booking->starts_at->setTimezone($team->timezone)->isoFormat('MMM D, HH:mm') }}
                                </span>
                                <x-appointments.status-badge :status="$booking->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
