<?php

use App\Data\TeamPermissions;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Rules\TeamName;
use App\Rules\TeamSlug;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public string $teamName = '';

    public string $teamSlug = '';

    public string $teamTimezone = 'UTC';

    public string $teamContactEmail = '';

    public string $teamLocale = 'en';

    public string $teamCurrency = 'EUR';

    public int $minimumLeadTimeMinutes = 120;

    public int $bookingHorizonDays = 60;

    public int $cancellationCutoffMinutes = 120;

    public int $reminderLeadTimeHours = 24;

    public bool $requiresApproval = false;

    public array $teamData = [];

    public array $members = [];

    public array $invitations = [];

    public array $availableRoles = [];

    public bool $isCurrentTeam = false;

    public function mount(Team $team): void
    {
        $this->teamModel = $team;

        $this->populateTeamData();
    }

    public function updateTeam(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
            'teamSlug' => ['required', 'string', new TeamSlug, Rule::unique('teams', 'slug')->ignore($this->teamModel->id)],
            'teamTimezone' => ['required', 'string', 'timezone:all'],
            'teamContactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'teamLocale' => ['required', 'string', Rule::in(['en'])],
            'teamCurrency' => ['required', 'string', Rule::in(['EUR', 'USD', 'GBP', 'CHF'])],
        ]);

        $team = DB::transaction(function () use ($validated) {
            $team = Team::whereKey($this->teamModel->id)->lockForUpdate()->firstOrFail();

            $team->update([
                'name' => $validated['teamName'],
                'slug' => $validated['teamSlug'],
                'timezone' => $validated['teamTimezone'],
                'contact_email' => $validated['teamContactEmail'] ?: null,
                'locale' => $validated['teamLocale'],
                'currency' => $validated['teamCurrency'],
            ]);

            return $team;
        });

        $this->teamModel = $team;

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Team updated.'));

        $this->redirectRoute('teams.edit', ['team' => $this->teamModel->fresh()->slug], navigate: true);
    }

    public function updateBookingPolicy(): void
    {
        Gate::authorize('update', $this->teamModel);

        $validated = $this->validate([
            'minimumLeadTimeMinutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'bookingHorizonDays' => ['required', 'integer', 'min:1', 'max:365'],
            'cancellationCutoffMinutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'reminderLeadTimeHours' => ['required', 'integer', 'min:1', 'max:168'],
            'requiresApproval' => ['required', 'boolean'],
        ]);

        $this->teamModel->update([
            'minimum_lead_time_minutes' => $validated['minimumLeadTimeMinutes'],
            'booking_horizon_days' => $validated['bookingHorizonDays'],
            'cancellation_cutoff_minutes' => $validated['cancellationCutoffMinutes'],
            'reminder_lead_time_hours' => $validated['reminderLeadTimeHours'],
            'requires_approval' => $validated['requiresApproval'],
        ]);

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Booking policy updated.'));
    }

    public function updateMember(int $userId, string $role): void
    {
        Gate::authorize('updateMember', $this->teamModel);

        $validated = Validator::make(['role' => $role], [
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ])->validate();

        $membership = $this->teamModel->memberships()
            ->where('user_id', $userId)
            ->firstOrFail();

        $newRole = TeamRole::from($validated['role']);

        if ($membership->role === TeamRole::Owner && $newRole !== TeamRole::Owner && $this->teamModel->isLastOwner($membership->user)) {
            throw ValidationException::withMessages([
                'role' => [__('This member is the last owner. Transfer ownership before changing their role.')],
            ]);
        }

        $membership->update(['role' => $newRole]);

        $this->populateTeamData();

        Flux::toast(variant: 'success', text: __('Member role updated.'));
    }

    private function populateTeamData(): void
    {
        $user = Auth::user();

        $team = $this->teamModel->fresh();

        $this->teamData = [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'is_personal' => $team->is_personal,
        ];

        $this->populateForms($team);

        $this->members = $team->members()->get()->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'avatar' => $member->avatar ?? null,
            'role' => $member->pivot->role->value,
            'role_label' => $member->pivot->role->label(),
        ])->toArray();

        $this->invitations = $team->invitations()
            ->whereNull('accepted_at')
            ->get()
            ->map(fn ($invitation) => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at->toISOString(),
            ])->toArray();

        $this->availableRoles = TeamRole::assignable();

        $this->isCurrentTeam = $user->isCurrentTeam($team);
    }

    private function populateForms(Team $team): void
    {
        $this->teamName = $team->name;
        $this->teamSlug = $team->slug;
        $this->teamTimezone = $team->timezone;
        $this->teamContactEmail = $team->contact_email ?? '';
        $this->teamLocale = $team->locale;
        $this->teamCurrency = $team->currency;

        $this->minimumLeadTimeMinutes = $team->minimum_lead_time_minutes;
        $this->bookingHorizonDays = $team->booking_horizon_days;
        $this->cancellationCutoffMinutes = $team->cancellation_cutoff_minutes;
        $this->reminderLeadTimeHours = $team->reminder_lead_time_hours;
        $this->requiresApproval = $team->requires_approval;
    }

    public function render()
    {
        $teamName = $this->teamData['name'] ?? $this->teamModel->name;

        $title = $this->permissions->canUpdateTeam
            ? __('Edit :name', ['name' => $teamName])
            : __('View :name', ['name' => $teamName]);

        return $this->view()->title($title);
    }

    #[Computed]
    public function permissions(): TeamPermissions
    {
        return Auth::user()->toTeamPermissions($this->teamModel);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Teams') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Teams')" :subheading="__('Manage your team settings')">
        <div class="space-y-10">
            <div class="space-y-6">
                @if ($this->permissions->canUpdateTeam)
                    <div>
                        <flux:heading>{{ __('Profile') }}</flux:heading>
                        <flux:subheading>{{ __('Name, booking URL, and contact details for this team') }}</flux:subheading>
                    </div>

                    <form wire:submit="updateTeam" class="space-y-6">
                        <flux:input wire:model="teamName" :label="__('Team name')" required data-test="team-name-input" />

                        <flux:field>
                            <flux:label>{{ __('Slug') }}</flux:label>
                            <flux:input wire:model.live.debounce.500ms="teamSlug" required data-test="team-slug-input" />
                            <flux:error name="teamSlug" />
                            <flux:description data-test="team-slug-preview">
                                {{ __('Booking URL: :url', ['url' => url('/').'/'.($teamSlug !== '' ? $teamSlug : $teamData['slug'])]) }}
                            </flux:description>
                        </flux:field>

                        <flux:select wire:model="teamTimezone" :label="__('Timezone')" :description="__('Drives all slot calculations for this team.')" data-test="team-timezone-select">
                            @foreach ($this->timezones as $timezone)
                                <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="teamContactEmail" type="email" :label="__('Contact email')" :description="__('Shown to customers on booking communication. Optional.')" data-test="team-contact-email-input" />

                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:select wire:model="teamLocale" :label="__('Locale')" data-test="team-locale-select">
                                <flux:select.option value="en">{{ __('English') }}</flux:select.option>
                            </flux:select>

                            <flux:select wire:model="teamCurrency" :label="__('Currency')" data-test="team-currency-select">
                                <flux:select.option value="EUR">EUR</flux:select.option>
                                <flux:select.option value="USD">USD</flux:select.option>
                                <flux:select.option value="GBP">GBP</flux:select.option>
                                <flux:select.option value="CHF">CHF</flux:select.option>
                            </flux:select>
                        </div>

                        <flux:button variant="primary" type="submit" data-test="team-save-button">
                            {{ __('Save') }}
                        </flux:button>
                    </form>
                @else
                    <div>
                        <flux:heading>{{ $teamData['name'] }}</flux:heading>
                    </div>
                @endif
            </div>

            @if ($this->permissions->canUpdateTeam)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Booking policy') }}</flux:heading>
                        <flux:subheading>{{ __('Rules applied to every booking made with this team') }}</flux:subheading>
                    </div>

                    <form wire:submit="updateBookingPolicy" class="space-y-6">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:input wire:model="minimumLeadTimeMinutes" type="number" min="0" max="10080" :label="__('Minimum lead time')" :description="__('Minutes between booking and appointment start. Default: 120.')" data-test="policy-lead-time-input" />

                            <flux:input wire:model="bookingHorizonDays" type="number" min="1" max="365" :label="__('Booking horizon')" :description="__('Days into the future that can be booked. Default: 60.')" data-test="policy-horizon-input" />

                            <flux:input wire:model="cancellationCutoffMinutes" type="number" min="0" max="10080" :label="__('Cancellation cut-off')" :description="__('Minutes before start until which customers can cancel. Default: 120.')" data-test="policy-cutoff-input" />

                            <flux:input wire:model="reminderLeadTimeHours" type="number" min="1" max="168" :label="__('Reminder lead time')" :description="__('Hours before start when reminders are sent. Default: 24.')" data-test="policy-reminder-input" />
                        </div>

                        <flux:field variant="inline">
                            <flux:switch wire:model="requiresApproval" data-test="policy-approval-switch" />
                            <flux:label>{{ __('Bookings require approval') }}</flux:label>
                            <flux:error name="requiresApproval" />
                        </flux:field>

                        <flux:button variant="primary" type="submit" data-test="policy-save-button">
                            {{ __('Save') }}
                        </flux:button>
                    </form>
                </div>
            @endif

            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading>{{ __('Team members') }}</flux:heading>
                        @if ($this->permissions->canAddMember || $this->permissions->canUpdateMember || $this->permissions->canRemoveMember)
                            <flux:subheading>{{ __('Manage who belongs to this team') }}</flux:subheading>
                        @endif
                    </div>

                    @if ($this->permissions->canCreateInvitation)
                        <flux:modal.trigger name="invite-member">
                            <flux:button variant="primary" icon="user-plus" data-test="invite-member-button">
                                {{ __('Invite member') }}
                            </flux:button>
                        </flux:modal.trigger>
                    @endif
                </div>

                <div class="space-y-3">
                    @foreach ($members as $member)
                        <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="member-row">
                            <div class="flex items-center gap-4">
                                <flux:avatar :name="$member['name']" :initials="strtoupper(substr($member['name'], 0, 1))" />
                                <div>
                                    <div class="font-medium">{{ $member['name'] }}</div>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member['email'] }}</flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($member['role'] !== 'owner' && $this->permissions->canUpdateMember)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="outline" size="sm" icon:trailing="chevron-down" data-test="member-role-trigger">
                                            {{ $member['role_label'] }}
                                        </flux:button>
                                        <flux:menu>
                                            @foreach ($availableRoles as $role)
                                                <flux:menu.item
                                                    as="button"
                                                    type="button"
                                                    wire:click="updateMember({{ $member['id'] }}, '{{ $role['value'] }}')"
                                                    data-test="member-role-option"
                                                >
                                                    {{ $role['label'] }}
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    <flux:badge color="zinc">{{ $member['role_label'] }}</flux:badge>
                                @endif

                                @if ($member['role'] !== 'owner' && $this->permissions->canTransferOwnership && ! $teamData['is_personal'])
                                    <flux:modal.trigger name="transfer-ownership-{{ $member['id'] }}">
                                        <flux:tooltip :content="__('Make owner')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="key"
                                                data-test="member-transfer-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif

                                @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                                    <flux:modal.trigger name="remove-member-{{ $member['id'] }}">
                                        <flux:tooltip :content="__('Remove member')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="member-remove-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                        </div>

                        @if ($member['role'] !== 'owner' && $this->permissions->canTransferOwnership && ! $teamData['is_personal'])
                            <livewire:pages::teams.transfer-ownership-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'transfer-ownership-'.$member['id']"
                                :key="'transfer-ownership-modal-'.$member['id']"
                            />
                        @endif

                        @if ($member['role'] !== 'owner' && $this->permissions->canRemoveMember)
                            <livewire:pages::teams.remove-member-modal
                                :team="$teamModel"
                                :member-id="$member['id']"
                                :member-name="$member['name']"
                                :modal-name="'remove-member-'.$member['id']"
                                :key="'remove-member-modal-'.$member['id']"
                            />
                        @endif
                    @endforeach
                </div>
            </div>

            @if (count($invitations) > 0)
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Pending invitations') }}</flux:heading>
                        <flux:subheading>{{ __('Invitations that have not been accepted yet') }}</flux:subheading>
                    </div>

                    <div class="space-y-3">
                        @foreach ($invitations as $invitation)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="invitation-row">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <flux:icon name="envelope" class="text-zinc-500" />
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $invitation['email'] }}</div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $invitation['role_label'] }}</flux:text>
                                    </div>
                                </div>

                                @if ($this->permissions->canCancelInvitation)
                                    <flux:modal.trigger name="cancel-invitation-{{ $invitation['code'] }}">
                                        <flux:tooltip :content="__('Cancel invitation')">
                                            <flux:button
                                                variant="ghost"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="invitation-cancel-button"
                                            />
                                        </flux:tooltip>
                                    </flux:modal.trigger>
                                @endif
                            </div>
                            @if ($this->permissions->canCancelInvitation)
                                <livewire:pages::teams.cancel-invitation-modal
                                    :team="$teamModel"
                                    :invitation-code="$invitation['code']"
                                    :invitation-email="$invitation['email']"
                                    :modal-name="'cancel-invitation-'.$invitation['code']"
                                    :key="'cancel-invitation-modal-'.$invitation['code']"
                                />
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
                <div class="space-y-6">
                    <div>
                        <flux:heading>{{ __('Delete team') }}</flux:heading>
                        <flux:subheading>{{ __('Permanently delete your team') }}</flux:subheading>
                    </div>

                    <div class="space-y-4 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100">
                        <div>
                            <p class="font-medium">{{ __('Warning') }}</p>
                            <p class="text-sm">{{ __('Please proceed with caution, this cannot be undone.') }}</p>
                        </div>

                        <flux:modal.trigger name="delete-team">
                            <flux:button variant="danger" data-test="delete-team-button">
                                {{ __('Delete team') }}
                            </flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>
            @endif
        </div>
    </x-pages::settings.layout>

    @if ($this->permissions->canCreateInvitation)
        <livewire:pages::teams.invite-member-modal :team="$teamModel" />
    @endif

    @if ($this->permissions->canDeleteTeam && ! $teamData['is_personal'])
        <livewire:pages::teams.delete-team-modal :team="$teamModel" />
    @endif
</section>
