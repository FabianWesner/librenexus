<?php

use App\Enums\CalendarColor;
use App\Models\Service;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Services')] class extends Component
{
    #[Locked]
    public Team $team;

    #[Locked]
    public ?int $serviceId = null;

    public bool $showArchived = false;

    public string $name = '';

    public string $description = '';

    public int $durationMinutes = 30;

    public int $bufferBeforeMinutes = 0;

    public int $bufferAfterMinutes = 0;

    public ?int $priceMinor = null;

    public string $color = 'indigo';

    public bool $isActive = true;

    public function mount(Team $current_team): void
    {
        $this->team = $current_team->refresh();

        Gate::authorize('viewAny', [Service::class, $this->team]);
    }

    public function openCreateForm(): void
    {
        Gate::authorize('create', [Service::class, $this->team]);

        $this->resetForm();

        Flux::modal('service-form')->show();
    }

    public function editService(int $serviceId): void
    {
        $service = Service::query()->findOrFail($serviceId);

        Gate::authorize('update', $service);

        $this->serviceId = $service->id;
        $this->name = $service->name;
        $this->description = $service->description ?? '';
        $this->durationMinutes = $service->duration_minutes;
        $this->bufferBeforeMinutes = $service->buffer_before_minutes;
        $this->bufferAfterMinutes = $service->buffer_after_minutes;
        $this->priceMinor = $service->price_minor;
        $this->color = $service->color->value;
        $this->isActive = $service->is_active;
        $this->resetErrorBag();

        Flux::modal('service-form')->show();
    }

    public function saveService(): void
    {
        $service = $this->serviceId !== null ? Service::query()->findOrFail($this->serviceId) : null;

        $service === null
            ? Gate::authorize('create', [Service::class, $this->team])
            : Gate::authorize('update', $service);

        // FR-SERVICE-3: duration 5-480 minutes, buffers 0-120 minutes,
        // price optional and non-negative in minor units.
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'durationMinutes' => ['required', 'integer', 'between:5,480'],
            'bufferBeforeMinutes' => ['required', 'integer', 'between:0,120'],
            'bufferAfterMinutes' => ['required', 'integer', 'between:0,120'],
            'priceMinor' => ['nullable', 'integer', 'between:0,10000000'],
            'color' => ['required', Rule::enum(CalendarColor::class)],
            'isActive' => ['boolean'],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'duration_minutes' => $validated['durationMinutes'],
            'buffer_before_minutes' => $validated['bufferBeforeMinutes'],
            'buffer_after_minutes' => $validated['bufferAfterMinutes'],
            'price_minor' => $validated['priceMinor'],
            'color' => CalendarColor::from($validated['color']),
            'is_active' => $validated['isActive'],
        ];

        if ($service === null) {
            Service::create($attributes);
        } else {
            $service->update($attributes);
        }

        $this->resetForm();
        unset($this->services);

        Flux::modal('service-form')->close();
        Flux::toast(variant: 'success', text: __('Service saved.'));
    }

    public function archiveService(int $serviceId): void
    {
        $service = Service::query()->findOrFail($serviceId);

        Gate::authorize('update', $service);

        $service->update(['is_active' => false]);

        unset($this->services);

        Flux::modal('archive-service-'.$serviceId)->close();
        Flux::toast(variant: 'success', text: __('Service archived.'));
    }

    public function restoreService(int $serviceId): void
    {
        $service = Service::query()->findOrFail($serviceId);

        Gate::authorize('update', $service);

        $service->update(['is_active' => true]);

        unset($this->services);

        Flux::toast(variant: 'success', text: __('Service restored.'));
    }

    private function resetForm(): void
    {
        $this->reset('serviceId', 'name', 'description', 'durationMinutes', 'bufferBeforeMinutes', 'bufferAfterMinutes', 'priceMinor', 'color', 'isActive');
        $this->resetErrorBag();
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()
            ->when(! $this->showArchived, fn ($query) => $query->bookable())
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', [Service::class, $this->team]);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Services') }}</flux:heading>
            <flux:subheading>{{ __('What customers can book with your team') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreateForm" data-test="add-service-button">
                {{ __('Add service') }}
            </flux:button>
        @endif
    </div>

    <div class="mt-6 flex items-center justify-end">
        <flux:field variant="inline">
            <flux:switch wire:model.live="showArchived" data-test="show-archived-switch" />
            <flux:label>{{ __('Show archived') }}</flux:label>
        </flux:field>
    </div>

    <div class="mt-4">
        @if ($this->services->isEmpty())
            <div class="rounded-xl border border-zinc-200 px-6 py-12 text-center dark:border-zinc-700" data-test="services-empty-state">
                <flux:heading>{{ __('Add your first service') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Services define what customers can book: duration, buffers, and price.') }}</flux:text>
                @if ($this->canManage)
                    <flux:button variant="primary" icon="plus" wire:click="openCreateForm" class="mt-4" data-test="services-empty-state-button">
                        {{ __('Add service') }}
                    </flux:button>
                @endif
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Duration') }}</flux:table.column>
                    <flux:table.column>{{ __('Buffers') }}</flux:table.column>
                    <flux:table.column>{{ __('Price') }}</flux:table.column>
                    <flux:table.column>{{ __('Color') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    @if ($this->canManage)
                        <flux:table.column>
                            <span class="sr-only">{{ __('Actions') }}</span>
                        </flux:table.column>
                    @endif
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->services as $service)
                        <flux:table.row :key="$service->id" data-test="service-row">
                            <flux:table.cell variant="strong">{{ $service->name }}</flux:table.cell>
                            <flux:table.cell>{{ __(':minutes min', ['minutes' => $service->duration_minutes]) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ __(':before min before, :after min after', ['before' => $service->buffer_before_minutes, 'after' => $service->buffer_after_minutes]) }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $service->formattedPrice($team->currency) ?? __('Free') }}</flux:table.cell>
                            <flux:table.cell>
                                <span class="inline-flex items-center gap-2">
                                    <span class="size-3 rounded-full" style="background-color: {{ $service->color->hex() }}" aria-hidden="true"></span>
                                    {{ $service->color->label() }}
                                </span>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($service->is_active)
                                    <flux:badge color="lime" size="sm">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Archived') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            @if ($this->canManage)
                                <flux:table.cell align="end">
                                    <flux:tooltip :content="__('Edit service')">
                                        <flux:button variant="ghost" size="sm" icon="pencil" wire:click="editService({{ $service->id }})" data-test="service-edit-button" />
                                    </flux:tooltip>

                                    @if ($service->is_active)
                                        <flux:modal.trigger name="archive-service-{{ $service->id }}">
                                            <flux:tooltip :content="__('Archive service')">
                                                <flux:button variant="ghost" size="sm" icon="archive-box" data-test="service-archive-button" />
                                            </flux:tooltip>
                                        </flux:modal.trigger>
                                    @else
                                        <flux:tooltip :content="__('Restore service')">
                                            <flux:button variant="ghost" size="sm" icon="arrow-uturn-left" wire:click="restoreService({{ $service->id }})" data-test="service-restore-button" />
                                        </flux:tooltip>
                                    @endif
                                </flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($this->canManage)
                @foreach ($this->services as $service)
                    @if ($service->is_active)
                        <flux:modal name="archive-service-{{ $service->id }}" focusable class="max-w-lg">
                            <form wire:submit="archiveService({{ $service->id }})" class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Archive service') }}</flux:heading>
                                    <flux:subheading>
                                        {{ __(':name will no longer be bookable. Past appointments are kept.', ['name' => $service->name]) }}
                                    </flux:subheading>
                                </div>
                                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                                    <flux:modal.close>
                                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" type="submit" data-test="service-archive-confirm">{{ __('Archive') }}</flux:button>
                                </div>
                            </form>
                        </flux:modal>
                    @endif
                @endforeach
            @endif
        @endif
    </div>

    @if ($this->canManage)
        <flux:modal name="service-form" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
            <form wire:submit="saveService" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $serviceId === null ? __('Add a service') : __('Edit service') }}</flux:heading>
                    <flux:subheading>{{ __('Duration, buffers, and price drive the slot engine and booking flow.') }}</flux:subheading>
                </div>

                <div class="space-y-4">
                    <flux:input wire:model="name" :label="__('Name')" required data-test="service-name-input" />

                    <flux:textarea wire:model="description" :label="__('Description')" rows="3" :description="__('Optional. Shown to customers on the booking page.')" data-test="service-description-input" />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="durationMinutes" type="number" min="5" max="480" required :label="__('Duration (min)')" data-test="service-duration-input" />

                        <flux:input wire:model="bufferBeforeMinutes" type="number" min="0" max="120" required :label="__('Buffer before (min)')" data-test="service-buffer-before-input" />

                        <flux:input wire:model="bufferAfterMinutes" type="number" min="0" max="120" required :label="__('Buffer after (min)')" data-test="service-buffer-after-input" />
                    </div>

                    <flux:input
                        wire:model="priceMinor"
                        type="number"
                        min="0"
                        :label="__('Price (minor units)')"
                        :description="__('In the smallest unit of :currency, e.g. 2500 = 25.00 :currency. Leave empty for no price.', ['currency' => $team->currency])"
                        data-test="service-price-input"
                    />

                    <flux:select wire:model="color" :label="__('Calendar color')" data-test="service-color-select">
                        @foreach (\App\Enums\CalendarColor::cases() as $calendarColor)
                            <flux:select.option value="{{ $calendarColor->value }}">{{ $calendarColor->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:field variant="inline">
                        <flux:switch wire:model="isActive" data-test="service-active-switch" />
                        <flux:label>{{ __('Active (bookable)') }}</flux:label>
                        <flux:error name="isActive" />
                    </flux:field>
                </div>

                <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" type="submit" data-test="service-save-button">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</section>
