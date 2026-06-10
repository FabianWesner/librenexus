<?php

use App\Models\AvailabilityRule;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Availability')] class extends Component
{
    private const string DEFAULT_START = '09:00';

    private const string DEFAULT_END = '17:00';

    #[Locked]
    public Team $team;

    #[Locked]
    public Staff $staffMember;

    /** @var array<int, string> new-rule start time per ISO weekday */
    public array $ruleStart = [];

    /** @var array<int, string> new-rule end time per ISO weekday */
    public array $ruleEnd = [];

    public string $timeOffStart = '';

    public string $timeOffEnd = '';

    public string $timeOffReason = '';

    public function mount(Team $current_team, string $staff): void
    {
        $this->team = $current_team->refresh();

        // The {staff} parameter is resolved here instead of through implicit
        // route binding: Livewire substitutes page-component bindings inside
        // the SubstituteBindings middleware, which runs before
        // EnsureTeamMembership sets the tenant context, so the tenant-scoped
        // query would fail closed for everyone. Resolving in mount runs after
        // the middleware: foreign tenants' staff stay invisible and 404
        // (SEC-TENANT).
        $this->staffMember = Staff::query()->findOrFail((int) $staff);

        Gate::authorize('manageAvailability', $this->staffMember);

        foreach (array_keys($this->weekdays) as $weekday) {
            $this->resetDayForm($weekday);
        }
    }

    public function addRule(int $weekday): void
    {
        Gate::authorize('manageAvailability', $this->staffMember);

        if ($weekday < 1 || $weekday > 7) {
            throw ValidationException::withMessages([
                'weekday' => __('Pick a weekday between Monday and Sunday.'),
            ]);
        }

        $validated = $this->validate([
            "ruleStart.{$weekday}" => ['required', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            "ruleEnd.{$weekday}" => ['required', 'string', 'regex:/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/'],
        ], [
            "ruleStart.{$weekday}.regex" => __('The start time must be a time like 09:00.'),
            "ruleEnd.{$weekday}.regex" => __('The end time must be a time like 17:00, or 24:00 for end of day.'),
        ]);

        $startTime = $validated['ruleStart'][$weekday];
        $endTime = $validated['ruleEnd'][$weekday];

        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                "ruleEnd.{$weekday}" => __('The end time must be after the start time.'),
            ]);
        }

        $this->ensureRuleDoesNotOverlap($weekday, $startTime, $endTime);

        $this->staffMember->availabilityRules()->create([
            'weekday' => $weekday,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        $this->resetDayForm($weekday);
        $this->resetErrorBag();
        unset($this->rulesByWeekday);

        Flux::toast(variant: 'success', text: __('Availability window added.'));
    }

    public function removeRule(int $ruleId): void
    {
        Gate::authorize('manageAvailability', $this->staffMember);

        $this->staffMember->availabilityRules()->findOrFail($ruleId)->delete();

        unset($this->rulesByWeekday);

        Flux::toast(variant: 'success', text: __('Availability window removed.'));
    }

    public function addTimeOff(): void
    {
        Gate::authorize('manageAvailability', $this->staffMember);

        $validated = $this->validate([
            'timeOffStart' => ['required', 'string', 'date_format:Y-m-d\TH:i'],
            'timeOffEnd' => ['required', 'string', 'date_format:Y-m-d\TH:i'],
            'timeOffReason' => ['nullable', 'string', 'max:255'],
        ]);

        // The inputs are local to the tenant timezone; storage is UTC
        // (FR-AVAIL-2, ARCH-DATA-2).
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['timeOffStart'], $this->team->timezone)->utc();
        $endsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['timeOffEnd'], $this->team->timezone)->utc();

        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages([
                'timeOffEnd' => __('The end must be after the start.'),
            ]);
        }

        $this->staffMember->timeOff()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $validated['timeOffReason'] === '' ? null : $validated['timeOffReason'],
        ]);

        $this->reset('timeOffStart', 'timeOffEnd', 'timeOffReason');
        $this->resetErrorBag();
        unset($this->timeOffEntries);

        Flux::toast(variant: 'success', text: __('Time off added.'));
    }

    public function removeTimeOff(int $timeOffId): void
    {
        Gate::authorize('manageAvailability', $this->staffMember);

        $this->staffMember->timeOff()->findOrFail($timeOffId)->delete();

        unset($this->timeOffEntries);

        Flux::toast(variant: 'success', text: __('Time off removed.'));
    }

    /**
     * Two windows on the same weekday must not overlap; touching windows
     * (end equals start) are allowed.
     */
    private function ensureRuleDoesNotOverlap(int $weekday, string $startTime, string $endTime): void
    {
        $overlaps = $this->staffMember->availabilityRules()
            ->where('weekday', $weekday)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                "ruleStart.{$weekday}" => __('This window overlaps an existing window on the same day.'),
            ]);
        }
    }

    private function resetDayForm(int $weekday): void
    {
        $this->ruleStart[$weekday] = self::DEFAULT_START;
        $this->ruleEnd[$weekday] = self::DEFAULT_END;
    }

    /**
     * @return array<int, string> ISO weekday number => label
     */
    #[Computed]
    public function weekdays(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
        ];
    }

    /**
     * @return list<string> start-time choices in 15-minute steps
     */
    #[Computed]
    public function startTimeOptions(): array
    {
        return $this->timeOptions(0, 23 * 60 + 45);
    }

    /**
     * @return list<string> end-time choices in 15-minute steps, up to 24:00
     */
    #[Computed]
    public function endTimeOptions(): array
    {
        return $this->timeOptions(15, 24 * 60);
    }

    /**
     * @return Collection<int, Collection<int, AvailabilityRule>> rules grouped by ISO weekday
     */
    #[Computed]
    public function rulesByWeekday(): Collection
    {
        return $this->staffMember->availabilityRules()
            ->orderBy('start_time')
            ->get()
            ->groupBy('weekday');
    }

    /**
     * @return Collection<int, TimeOff>
     */
    #[Computed]
    public function timeOffEntries(): Collection
    {
        return $this->staffMember->timeOff()
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function timeOptions(int $fromMinutes, int $untilMinutes): array
    {
        $options = [];

        for ($minutes = $fromMinutes; $minutes <= $untilMinutes; $minutes += 15) {
            $options[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $options;
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Availability for :name', ['name' => $staffMember->name]) }}</flux:heading>
            <flux:subheading>{{ __('All times are in :timezone.', ['timezone' => $team->timezone]) }}</flux:subheading>
        </div>

        <flux:button icon="arrow-left" :href="route('staff.index', ['current_team' => $team->slug])" data-test="back-to-staff-link">
            {{ __('Back to staff') }}
        </flux:button>
    </div>

    <flux:error name="weekday" />

    <div class="mt-6">
        <flux:heading size="lg">{{ __('Weekly hours') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Recurring working hours. Slots are offered inside these windows.') }}</flux:text>

        <div class="mt-4 space-y-3">
            @foreach ($this->weekdays as $weekday => $dayName)
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="weekday-{{ $weekday }}">
                    <div class="grid gap-4 lg:grid-cols-[10rem_1fr_auto]">
                        <flux:heading>{{ $dayName }}</flux:heading>

                        <div class="space-y-2">
                            @forelse ($this->rulesByWeekday->get($weekday, collect()) as $rule)
                                <div class="flex items-center gap-2" data-test="rule-row">
                                    <flux:text variant="strong">
                                        {{ substr($rule->start_time, 0, 5) }} - {{ substr($rule->end_time, 0, 5) }}
                                    </flux:text>
                                    <flux:tooltip :content="__('Remove this window')">
                                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeRule({{ $rule->id }})" data-test="rule-remove-button" />
                                    </flux:tooltip>
                                </div>
                            @empty
                                <flux:text data-test="weekday-empty-state">{{ __('Not available.') }}</flux:text>
                            @endforelse
                        </div>

                        <form wire:submit="addRule({{ $weekday }})" class="flex flex-wrap items-end gap-2">
                            <flux:select wire:model="ruleStart.{{ $weekday }}" :label="__('Start')" size="sm" data-test="rule-start-input-{{ $weekday }}">
                                @foreach ($this->startTimeOptions as $time)
                                    <flux:select.option value="{{ $time }}">{{ $time }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model="ruleEnd.{{ $weekday }}" :label="__('End')" size="sm" data-test="rule-end-input-{{ $weekday }}">
                                @foreach ($this->endTimeOptions as $time)
                                    <flux:select.option value="{{ $time }}">{{ $time }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:button type="submit" size="sm" icon="plus" data-test="rule-add-button-{{ $weekday }}">
                                {{ __('Add') }}
                            </flux:button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mt-10">
        <flux:heading size="lg">{{ __('Time off') }}</flux:heading>
        <flux:text class="mt-1">{{ __('One-off breaks such as vacation or appointments. No slots are offered during time off.') }}</flux:text>

        <div class="mt-4">
            @if ($this->timeOffEntries->isEmpty())
                <div class="rounded-xl border border-zinc-200 px-6 py-8 text-center dark:border-zinc-700" data-test="timeoff-empty-state">
                    <flux:heading>{{ __('No time off planned') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Add vacation or other breaks below to block bookings.') }}</flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('From') }}</flux:table.column>
                        <flux:table.column>{{ __('Until') }}</flux:table.column>
                        <flux:table.column>{{ __('Reason') }}</flux:table.column>
                        <flux:table.column>
                            <span class="sr-only">{{ __('Actions') }}</span>
                        </flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->timeOffEntries as $timeOff)
                            <flux:table.row :key="$timeOff->id" data-test="timeoff-row">
                                <flux:table.cell variant="strong">{{ $timeOff->starts_at->timezone($team->timezone)->format('D, M j, Y H:i') }}</flux:table.cell>
                                <flux:table.cell variant="strong">{{ $timeOff->ends_at->timezone($team->timezone)->format('D, M j, Y H:i') }}</flux:table.cell>
                                <flux:table.cell>{{ $timeOff->reason }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:tooltip :content="__('Remove this time off')">
                                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="removeTimeOff({{ $timeOff->id }})" data-test="timeoff-remove-button" />
                                    </flux:tooltip>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>

        <form wire:submit="addTimeOff" class="mt-6 space-y-4">
            <flux:heading>{{ __('Add time off') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="datetime-local" step="900" wire:model="timeOffStart" :label="__('From')" required data-test="timeoff-start-input" />
                <flux:input type="datetime-local" step="900" wire:model="timeOffEnd" :label="__('Until')" required data-test="timeoff-end-input" />
            </div>

            <flux:input wire:model="timeOffReason" :label="__('Reason')" :description="__('Optional, for example vacation.')" data-test="timeoff-reason-input" />

            <flux:button type="submit" variant="primary" icon="plus" data-test="timeoff-add-button">
                {{ __('Add time off') }}
            </flux:button>
        </form>
    </div>
</section>
