<?php

use App\Enums\CalendarColor;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff')] class extends Component
{
    #[Locked]
    public Team $team;

    #[Locked]
    public ?int $staffId = null;

    public string $name = '';

    public string $email = '';

    public string $color = 'indigo';

    public bool $isActive = true;

    /** @var array<int, int|string> */
    public array $serviceIds = [];

    public ?int $membershipId = null;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team->refresh();

        Gate::authorize('viewAny', [Staff::class, $this->team]);
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', [Staff::class, $this->team]);

        $this->resetForm();

        Flux::modal('staff-form')->show();
    }

    public function editStaff(int $staffId): void
    {
        $staffMember = Staff::query()->findOrFail($staffId);

        Gate::authorize('update', $staffMember);

        $this->staffId = $staffMember->id;
        $this->name = $staffMember->name;
        $this->email = $staffMember->email ?? '';
        $this->color = $staffMember->color->value;
        $this->isActive = $staffMember->is_active;
        $this->serviceIds = $staffMember->services()->pluck('services.id')->all();
        $this->membershipId = $staffMember->membership_id;
        $this->resetErrorBag();

        Flux::modal('staff-form')->show();
    }

    public function saveStaff(): void
    {
        $staffMember = $this->staffId !== null ? Staff::query()->findOrFail($this->staffId) : null;

        $staffMember === null
            ? Gate::authorize('create', [Staff::class, $this->team])
            : Gate::authorize('update', $staffMember);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'color' => ['required', Rule::enum(CalendarColor::class)],
            'isActive' => ['boolean'],
            'serviceIds' => ['array'],
            'serviceIds.*' => ['integer', Rule::exists('services', 'id')->where('team_id', $this->team->id)],
            'membershipId' => [
                'nullable',
                'integer',
                Rule::exists('team_members', 'id')->where('team_id', $this->team->id),
                Rule::unique('staff', 'membership_id')->ignore($this->staffId),
            ],
        ]);

        $this->ensureNotSelfLinking($staffMember, $validated['membershipId']);

        $attributes = [
            'name' => $validated['name'],
            'email' => $validated['email'] !== '' ? $validated['email'] : null,
            'color' => CalendarColor::from($validated['color']),
            'is_active' => $validated['isActive'],
            'membership_id' => $validated['membershipId'],
        ];

        if ($staffMember === null) {
            $staffMember = Staff::create($attributes);
        } else {
            $staffMember->update($attributes);
        }

        $staffMember->services()->sync($validated['serviceIds']);

        $this->resetForm();
        unset($this->staffMembers, $this->linkableMemberships);

        Flux::modal('staff-form')->close();
        Flux::toast(variant: 'success', text: __('Staff member saved.'));
    }

    public function deactivateStaff(int $staffId): void
    {
        $staffMember = Staff::query()->findOrFail($staffId);

        Gate::authorize('update', $staffMember);

        $staffMember->update(['is_active' => false]);

        unset($this->staffMembers);

        Flux::modal('deactivate-staff-'.$staffId)->close();
        Flux::toast(variant: 'success', text: __('Staff member deactivated.'));
    }

    public function reactivateStaff(int $staffId): void
    {
        $staffMember = Staff::query()->findOrFail($staffId);

        Gate::authorize('update', $staffMember);

        $staffMember->update(['is_active' => true]);

        unset($this->staffMembers);

        Flux::toast(variant: 'success', text: __('Staff member reactivated.'));
    }

    /**
     * A user can never link a staff record to their own membership (AC-6);
     * an existing link made by another admin may be kept on update.
     */
    private function ensureNotSelfLinking(?Staff $staffMember, ?int $membershipId): void
    {
        if ($membershipId === null || $staffMember?->membership_id === $membershipId) {
            return;
        }

        $membership = Membership::query()->findOrFail($membershipId);

        if ($membership->user_id === Auth::id()) {
            throw ValidationException::withMessages([
                'membershipId' => [__('You cannot link a staff record to your own membership. Another admin has to do that.')],
            ]);
        }
    }

    private function resetForm(): void
    {
        $this->reset('staffId', 'name', 'email', 'color', 'isActive', 'serviceIds', 'membershipId');
        $this->resetErrorBag();
    }

    /**
     * @return Collection<int, Staff>
     */
    #[Computed]
    public function staffMembers(): Collection
    {
        return Staff::query()
            ->withCount('services')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function serviceOptions(): Collection
    {
        return Service::query()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Memberships that can be linked to the open staff record: not the
     * acting user's own (no self-linking, AC-6) and not already linked to
     * another staff record (FR-STAFF-4), unless linked to this very record.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function linkableMemberships(): array
    {
        $linkedElsewhere = Staff::query()
            ->whereNotNull('membership_id')
            ->when($this->staffId !== null, fn ($query) => $query->whereKeyNot($this->staffId))
            ->pluck('membership_id');

        return $this->team->memberships()
            ->with('user:id,name,email')
            ->where(fn ($query) => $query
                ->where('user_id', '!=', Auth::id())
                ->when($this->membershipId !== null, fn ($q) => $q->orWhere('id', $this->membershipId)))
            ->whereNotIn('id', $linkedElsewhere)
            ->get()
            ->map(fn (Membership $membership) => [
                'id' => $membership->id,
                'name' => $membership->user->name.' ('.$membership->user->email.')',
            ])
            ->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [Staff::class, $this->team]);
    }

    /**
     * The staff record linked to the acting user's own membership, if any.
     * A staff-role member may manage exactly this record's availability
     * (StaffPolicy::manageAvailability, FR-STAFF-4); the server-side
     * authorization lives on the availability page itself.
     */
    #[Computed]
    public function ownStaffId(): ?int
    {
        $staffId = Staff::query()
            ->whereRelation('membership', 'user_id', Auth::id())
            ->value('id');

        return $staffId === null ? null : (int) $staffId;
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Staff') }}</flux:heading>
            <flux:subheading>{{ __('The people who deliver your services') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreateForm" data-test="add-staff-button">
                {{ __('Add staff') }}
            </flux:button>
        @endif
    </div>

    <div class="mt-6">
        @if ($this->staffMembers->isEmpty())
            <div class="rounded-xl border border-zinc-200 px-6 py-12 text-center dark:border-zinc-700" data-test="staff-empty-state">
                <flux:heading>{{ __('Add your first staff member') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Staff members can be assigned to services and take bookings.') }}</flux:text>
                @if ($this->canManage)
                    <flux:button variant="primary" icon="plus" wire:click="openCreateForm" class="mt-4" data-test="staff-empty-state-button">
                        {{ __('Add staff') }}
                    </flux:button>
                @endif
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Email') }}</flux:table.column>
                    <flux:table.column>{{ __('Color') }}</flux:table.column>
                    <flux:table.column>{{ __('Services') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    @if ($this->canManage || $this->ownStaffId !== null)
                        <flux:table.column>
                            <span class="sr-only">{{ __('Actions') }}</span>
                        </flux:table.column>
                    @endif
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->staffMembers as $staffMember)
                        <flux:table.row :key="$staffMember->id" data-test="staff-row">
                            <flux:table.cell variant="strong">{{ $staffMember->name }}</flux:table.cell>
                            <flux:table.cell>{{ $staffMember->email }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-3 rounded-full" style="background-color: {{ $staffMember->color->hex() }}" aria-hidden="true"></span>
                                    {{ $staffMember->color->label() }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>{{ $staffMember->services_count }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($staffMember->is_active)
                                    <flux:badge color="lime" size="sm">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            @if ($this->canManage || $this->ownStaffId !== null)
                                <flux:table.cell align="end">
                                    @if ($this->canManage || $staffMember->id === $this->ownStaffId)
                                        <flux:tooltip :content="__('Edit availability')">
                                            <flux:button variant="ghost" size="sm" icon="clock" :href="route('staff.availability', ['current_team' => $team->slug, 'staff' => $staffMember->id])" data-test="staff-availability-link" />
                                        </flux:tooltip>
                                    @endif

                                    @if ($this->canManage)
                                        <flux:tooltip :content="__('Edit staff member')">
                                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="editStaff({{ $staffMember->id }})" data-test="staff-edit-button" />
                                        </flux:tooltip>

                                        @if ($staffMember->is_active)
                                            <flux:modal.trigger name="deactivate-staff-{{ $staffMember->id }}">
                                                <flux:tooltip :content="__('Deactivate staff member')">
                                                    <flux:button variant="ghost" size="sm" icon="pause" data-test="staff-deactivate-button" />
                                                </flux:tooltip>
                                            </flux:modal.trigger>
                                        @else
                                            <flux:tooltip :content="__('Reactivate staff member')">
                                                <flux:button variant="ghost" size="sm" icon="play" wire:click="reactivateStaff({{ $staffMember->id }})" data-test="staff-reactivate-button" />
                                            </flux:tooltip>
                                        @endif
                                    @endif
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($this->canManage)
                @foreach ($this->staffMembers as $staffMember)
                    @if ($staffMember->is_active)
                        <flux:modal name="deactivate-staff-{{ $staffMember->id }}" focusable class="max-w-lg">
                            <form wire:submit="deactivateStaff({{ $staffMember->id }})" class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Deactivate staff member') }}</flux:heading>
                                    <flux:subheading>
                                        {{ __(':name will no longer be bookable. Past appointments are kept.', ['name' => $staffMember->name]) }}
                                    </flux:subheading>
                                </div>
                                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                    <flux:modal.close>
                                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" type="submit" data-test="staff-deactivate-confirm">{{ __('Deactivate') }}</flux:button>
                                </div>
                            </form>
                        </flux:modal>
                    @endif
                @endforeach
            @endif
        @endif
    </div>

    @if ($this->canManage)
        <flux:modal name="staff-form" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
            <form wire:submit="saveStaff" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $staffId === null ? __('Add a staff member') : __('Edit staff member') }}</flux:heading>
                    <flux:subheading>{{ __('Staff members can be assigned to services and take bookings.') }}</flux:subheading>
                </div>

                <div class="space-y-4">
                    <flux:input wire:model="name" :label="__('Name')" required data-test="staff-name-input" />

                    <flux:input wire:model="email" type="email" :label="__('Email')" :description="__('Optional. Used for notifications later on.')" data-test="staff-email-input" />

                    <flux:select wire:model="color" :label="__('Calendar color')" data-test="staff-color-select">
                        @foreach (\App\Enums\CalendarColor::cases() as $calendarColor)
                            <flux:select.option value="{{ $calendarColor->value }}">{{ $calendarColor->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($this->serviceOptions->isNotEmpty())
                        <flux:checkbox.group wire:model="serviceIds" :label="__('Services')" :description="__('Which services this staff member can deliver.')">
                            @foreach ($this->serviceOptions as $service)
                                <flux:checkbox value="{{ $service->id }}" :label="$service->name" data-test="staff-service-checkbox" />
                            @endforeach
                        </flux:checkbox.group>
                        <flux:error name="serviceIds" />
                    @endif

                    <flux:select wire:model="membershipId" :label="__('Linked member')" :description="__('Connect this staff record to a team member account. You cannot link yourself.')" data-test="staff-membership-select">
                        <flux:select.option value="">{{ __('Not linked') }}</flux:select.option>
                        @foreach ($this->linkableMemberships as $membership)
                            <flux:select.option value="{{ $membership['id'] }}">{{ $membership['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:field variant="inline">
                        <flux:switch wire:model="isActive" data-test="staff-active-switch" />
                        <flux:label>{{ __('Active (bookable)') }}</flux:label>
                        <flux:error name="isActive" />
                    </flux:field>
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="staff-save-button">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
