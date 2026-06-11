<?php

use App\Enums\AppointmentStatus;
use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Calendar day view (FR-APPT-1, pages.md §Calendar / day view): one column
 * per bookable staff member for admins, only the own column for staff-role
 * members (FR-APPT-2, enforced in the query). Appointments render as
 * colored blocks (CalendarColor, white text plus text labels, never color
 * alone) on a 07:00-20:00 time grid in the tenant timezone; cancelled and
 * no-show appointments freed their slot and are not drawn (FR-APPT-4).
 */
new #[Title('Calendar')] class extends Component
{
    /** The visible window: 07:00-20:00 local in quarter-hour grid rows. */
    private const int DAY_START_HOUR = 7;

    private const int DAY_END_HOUR = 20;

    private const int MINUTES_PER_ROW = 15;

    #[Locked]
    public Team $team;

    #[Url]
    public string $day = '';

    #[Locked]
    public ?int $detailAppointmentId = null;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team->refresh();

        Gate::authorize('viewAny', [Appointment::class, $this->team]);

        if ($this->parsedDay() === null) {
            $this->day = $this->today();
        }
    }

    public function goToPreviousDay(): void
    {
        $this->day = ($this->parsedDay() ?? $this->todayDate())->subDay()->format('Y-m-d');
        unset($this->dayColumns);
    }

    public function goToNextDay(): void
    {
        $this->day = ($this->parsedDay() ?? $this->todayDate())->addDay()->format('Y-m-d');
        unset($this->dayColumns);
    }

    public function goToToday(): void
    {
        $this->day = $this->today();
        unset($this->dayColumns);
    }

    public function updatedDay(): void
    {
        if ($this->parsedDay() === null) {
            $this->day = $this->today();
        }

        unset($this->dayColumns);
    }

    public function openDetail(int $appointmentId): void
    {
        $appointment = Appointment::query()->findOrFail($appointmentId);

        Gate::authorize('view', $appointment);

        $this->detailAppointmentId = $appointment->id;

        Flux::modal('appointment-detail')->show();
    }

    /**
     * One column per visible staff member with its appointment blocks,
     * positioned as quarter-hour grid rows and clamped to the visible
     * window. Built from exactly two queries (NFR-PERF, AC-1).
     *
     * @return list<array{staff: Staff, blocks: list<array{id: int, timeLabel: string, customerName: string, serviceName: string, statusLabel: string, colorHex: string, rowStart: int, rowEnd: int}>}>
     */
    #[Computed]
    public function dayColumns(): array
    {
        $staffMembers = $this->visibleStaff();
        $windowStart = ($this->parsedDay() ?? $this->todayDate())->setTime(self::DAY_START_HOUR, 0);
        $windowEnd = $windowStart->setTime(self::DAY_END_HOUR, 0);

        $appointmentsByStaff = Appointment::query()
            ->with(['service', 'customer'])
            ->whereIn('staff_id', $staffMembers->modelKeys())
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Completed])
            ->where('starts_at', '<', $windowStart->startOfDay()->addDay()->utc())
            ->where('ends_at', '>', $windowStart->startOfDay()->utc())
            ->orderBy('starts_at')
            ->get()
            ->groupBy('staff_id');

        return $staffMembers
            ->map(fn (Staff $staffMember): array => [
                'staff' => $staffMember,
                'blocks' => $appointmentsByStaff->get($staffMember->id, collect())
                    ->map(fn (Appointment $appointment): array => $this->blockFor($appointment, $staffMember, $windowStart, $windowEnd))
                    ->all(),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function detailAppointment(): ?Appointment
    {
        return $this->detailAppointmentId === null
            ? null
            : Appointment::query()->with(['staff', 'service', 'customer'])->find($this->detailAppointmentId);
    }

    /**
     * Owners and admins see every staff column (FR-APPT-2).
     */
    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->teamRole($this->team)?->isAtLeast(TeamRole::Admin) ?? false;
    }

    #[Computed]
    public function dayLabel(): string
    {
        return ($this->parsedDay() ?? $this->todayDate())->isoFormat('dddd, MMMM D, YYYY');
    }

    /**
     * The current time as a percentage of the visible window, or null when
     * the selected day is not today or the time is outside the window.
     */
    #[Computed]
    public function currentTimePercent(): ?float
    {
        if ($this->day !== $this->today()) {
            return null;
        }

        $now = CarbonImmutable::now($this->team->timezone);
        $minutes = ($now->hour - self::DAY_START_HOUR) * 60 + $now->minute;
        $windowMinutes = (self::DAY_END_HOUR - self::DAY_START_HOUR) * 60;

        if ($minutes < 0 || $minutes > $windowMinutes) {
            return null;
        }

        return round($minutes / $windowMinutes * 100, 2);
    }

    /**
     * @return list<int>
     */
    public function hours(): array
    {
        return range(self::DAY_START_HOUR, self::DAY_END_HOUR - 1);
    }

    public function rowCount(): int
    {
        return (self::DAY_END_HOUR - self::DAY_START_HOUR) * 60 / self::MINUTES_PER_ROW;
    }

    public function rowStartForHour(int $hour): int
    {
        return ($hour - self::DAY_START_HOUR) * 60 / self::MINUTES_PER_ROW + 1;
    }

    /**
     * The staff whose columns the user may see: all bookable staff for
     * admins/owners, only the linked own record for staff-role members
     * (FR-APPT-2, server-side).
     *
     * @return EloquentCollection<int, Staff>
     */
    private function visibleStaff(): EloquentCollection
    {
        if ($this->canManage) {
            return Staff::query()->bookable()->orderBy('name')->get();
        }

        $ownStaff = Auth::user()->staffRecordFor($this->team);

        return $ownStaff === null
            ? new EloquentCollection
            : new EloquentCollection([$ownStaff]);
    }

    /**
     * Map an appointment to a renderable block: quarter-hour grid rows
     * clamped to the visible window, plus the text labels.
     *
     * @return array{id: int, timeLabel: string, customerName: string, serviceName: string, statusLabel: string, colorHex: string, rowStart: int, rowEnd: int}
     */
    private function blockFor(Appointment $appointment, Staff $staffMember, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $startsLocal = CarbonImmutable::parse($appointment->starts_at)->setTimezone($this->team->timezone);
        $endsLocal = CarbonImmutable::parse($appointment->ends_at)->setTimezone($this->team->timezone);

        $rowStart = $this->rowForMinutes($windowStart->diffInMinutes($startsLocal->greaterThan($windowStart) ? $startsLocal : $windowStart));
        $rowEnd = $this->rowForMinutes($windowStart->diffInMinutes($endsLocal->lessThan($windowEnd) ? $endsLocal : $windowEnd));

        return [
            'id' => $appointment->id,
            'timeLabel' => $startsLocal->format('H:i').' - '.$endsLocal->format('H:i'),
            'customerName' => $appointment->customer->name,
            'serviceName' => $appointment->service->name,
            'statusLabel' => $appointment->status->label(),
            'colorHex' => $staffMember->color->hex(),
            'rowStart' => $rowStart,
            'rowEnd' => max($rowEnd, $rowStart + 1),
        ];
    }

    private function rowForMinutes(float $minutes): int
    {
        $row = (int) round($minutes / self::MINUTES_PER_ROW) + 1;

        return max(1, min($row, $this->rowCount() + 1));
    }

    private function parsedDay(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->day) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $this->day, $this->team->timezone) ?: null;
    }

    private function todayDate(): CarbonImmutable
    {
        return CarbonImmutable::now($this->team->timezone)->startOfDay();
    }

    private function today(): string
    {
        return $this->todayDate()->format('Y-m-d');
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Calendar') }}</flux:heading>
            <flux:subheading>{{ __('Day view in :timezone time', ['timezone' => $team->timezone]) }}</flux:subheading>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-2" data-test="calendar-day-nav">
        <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="goToPreviousDay" :aria-label="__('Previous day')" data-test="calendar-previous-day" />
        <flux:button variant="filled" size="sm" wire:click="goToToday" data-test="calendar-today">{{ __('Today') }}</flux:button>
        <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="goToNextDay" :aria-label="__('Next day')" data-test="calendar-next-day" />
        <flux:input wire:model.live="day" type="date" :aria-label="__('Pick a day')" class="max-w-40" data-test="calendar-date-input" />
        <flux:heading size="lg" level="2" class="ms-2">{{ $this->dayLabel }}</flux:heading>
    </div>

    @if (empty($this->dayColumns))
        <div class="mt-6 rounded-xl border border-zinc-200 px-6 py-12 text-center dark:border-zinc-700" data-test="calendar-empty-state">
            <flux:heading>{{ __('Nothing to show') }}</flux:heading>
            <flux:text class="mt-2">{{ __('There are no bookable staff members linked to this view yet.') }}</flux:text>
        </div>
    @else
        {{-- Desktop: time grid with one column per staff member. --}}
        <div class="mt-6 hidden overflow-x-auto md:block">
            <div class="min-w-[40rem]">
                <div class="flex border-b border-zinc-200 pb-2 dark:border-zinc-700">
                    <div class="w-14 shrink-0"></div>
                    @foreach ($this->dayColumns as $column)
                        <div class="flex-1 px-2">
                            <span class="inline-flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                <span class="size-3 rounded-full" style="background-color: {{ $column['staff']->color->hex() }}" aria-hidden="true"></span>
                                {{ $column['staff']->name }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="relative flex" data-test="calendar-grid">
                    @if ($this->currentTimePercent !== null)
                        <div class="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-red-600" style="top: {{ $this->currentTimePercent }}%" data-test="calendar-now-marker" aria-hidden="true"></div>
                    @endif

                    <div class="grid w-14 shrink-0 grid-rows-[repeat(52,1.25rem)]" aria-hidden="true">
                        @foreach ($this->hours() as $hour)
                            <div class="row-span-4 text-xs text-zinc-500 dark:text-zinc-400" style="grid-row-start: {{ $this->rowStartForHour($hour) }}">
                                {{ sprintf('%02d:00', $hour) }}
                            </div>
                        @endforeach
                    </div>

                    @foreach ($this->dayColumns as $column)
                        <div class="grid flex-1 grid-cols-1 grid-rows-[repeat(52,1.25rem)] border-s border-zinc-200 dark:border-zinc-700" data-test="calendar-column">
                            @foreach ($this->hours() as $hour)
                                <div class="col-start-1 row-span-4 border-t border-zinc-100 dark:border-zinc-800" style="grid-row-start: {{ $this->rowStartForHour($hour) }}" aria-hidden="true"></div>
                            @endforeach

                            @foreach ($column['blocks'] as $block)
                                <button type="button" wire:key="block-{{ $block['id'] }}" wire:click="openDetail({{ $block['id'] }})" data-test="calendar-block"
                                    class="z-10 col-start-1 m-px overflow-hidden rounded-md px-2 py-1 text-left text-xs text-white focus:outline-2 focus:outline-offset-2 focus:outline-brand-600"
                                    style="grid-row: {{ $block['rowStart'] }} / {{ $block['rowEnd'] }}; background-color: {{ $block['colorHex'] }}">
                                    <span class="block font-semibold">{{ $block['timeLabel'] }}</span>
                                    <span class="block truncate">{{ $block['customerName'] }}</span>
                                    <span class="block truncate">{{ $block['serviceName'] }} ({{ $block['statusLabel'] }})</span>
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Mobile: simplified stacked list per staff member. --}}
        <div class="mt-6 space-y-6 md:hidden" data-test="calendar-mobile-list">
            @foreach ($this->dayColumns as $column)
                <div>
                    <flux:heading size="sm" level="3" class="flex items-center gap-2">
                        <span class="size-3 rounded-full" style="background-color: {{ $column['staff']->color->hex() }}" aria-hidden="true"></span>
                        {{ $column['staff']->name }}
                    </flux:heading>

                    @if (empty($column['blocks']))
                        <flux:text class="mt-2">{{ __('No appointments on this day.') }}</flux:text>
                    @else
                        <ul class="mt-2 space-y-2">
                            @foreach ($column['blocks'] as $block)
                                <li wire:key="mobile-block-{{ $block['id'] }}">
                                    <button type="button" wire:click="openDetail({{ $block['id'] }})"
                                        class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-left text-sm transition hover:border-brand-500 focus:outline-2 focus:outline-offset-2 focus:outline-brand-600 dark:border-zinc-700">
                                        <span class="block font-medium text-zinc-900 dark:text-white">{{ $block['timeLabel'] }} {{ $block['customerName'] }}</span>
                                        <span class="block text-zinc-600 dark:text-zinc-400">{{ $block['serviceName'] }} ({{ $block['statusLabel'] }})</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal name="appointment-detail" focusable class="max-w-lg">
        @if ($this->detailAppointment)
            <x-appointments.detail :appointment="$this->detailAppointment" :team="$team" />
        @endif
    </flux:modal>
</section>
