<?php

use App\Actions\Teams\TransferTeamOwnership;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public Team $team;

    public ?int $memberId = null;

    public string $memberName = '';

    public string $modalName = 'transfer-ownership';

    public function mount(
        Team $team,
        ?int $memberId = null,
        ?string $memberName = null,
        ?string $modalName = null,
    ): void
    {
        $this->team = $team;
        $this->memberId = $memberId;
        $this->memberName = $memberName ?? '';
        $this->modalName = $modalName ?? ($memberId ? "transfer-ownership-{$memberId}" : 'transfer-ownership');
    }

    public function transferOwnership(TransferTeamOwnership $transferTeamOwnership): void
    {
        Gate::authorize('transferOwnership', $this->team);

        $newOwner = User::findOrFail($this->memberId);

        $transferTeamOwnership->handle($this->team, $newOwner);

        $this->dispatch('close-modal', name: $this->modalName);

        Flux::toast(variant: 'success', text: __('Ownership transferred.'));

        $this->redirectRoute('teams.edit', ['team' => $this->team->slug], navigate: true);
    }
}; ?>

<flux:modal :name="$modalName" focusable class="max-w-lg">
    <form wire:submit="transferOwnership" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Transfer ownership') }}</flux:heading>
            <flux:subheading>
                {{ __('Are you sure you want to make :name the owner of this team? Your role will become admin.', ['name' => $memberName]) }}
            </flux:subheading>
        </div>
        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="transfer-ownership-confirm">{{ __('Make owner') }}</flux:button>
        </div>
    </form>
</flux:modal>
