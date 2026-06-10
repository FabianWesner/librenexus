<?php

use App\Actions\Teams\CreateTeam;
use App\Rules\TeamName;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $teamName = '';

    public string $teamTimezone = 'UTC';

    public string $teamContactEmail = '';

    public function createTeam(CreateTeam $createTeam): void
    {
        $validated = $this->validate([
            'teamName' => ['required', 'string', 'max:255', new TeamName],
            'teamTimezone' => ['required', 'string', 'timezone:all'],
            'teamContactEmail' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        $team = $createTeam->handle(Auth::user(), $validated['teamName'], attributes: [
            'timezone' => $validated['teamTimezone'],
            'contact_email' => $validated['teamContactEmail'] ?: null,
        ]);

        $this->dispatch('close-modal', name: 'create-team-switcher');

        $this->reset('teamName', 'teamTimezone', 'teamContactEmail');

        Flux::toast(variant: 'success', text: __('Team created.'));

        $this->redirectRoute('teams.edit', ['team' => $team->slug], navigate: true);
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

<flux:modal name="create-team-switcher" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form wire:submit="createTeam" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Create a new team') }}</flux:heading>
            <flux:subheading>{{ __('Give your team a name to get started.') }}</flux:subheading>
        </div>

        <flux:input wire:model="teamName" :label="__('Team name')" type="text" required autofocus data-test="switcher-create-team-name" />

        <flux:select wire:model="teamTimezone" :label="__('Timezone')" data-test="switcher-create-team-timezone">
            @foreach ($this->timezones as $timezone)
                <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="teamContactEmail" :label="__('Contact email (optional)')" type="email" data-test="switcher-create-team-contact-email" />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" type="submit" data-test="switcher-create-team-submit">
                {{ __('Create team') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
