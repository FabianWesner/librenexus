<?php

use App\Data\CurrentTenant;
use App\Enums\CalendarColor;
use App\Enums\TeamRole;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Service CRUD, FR-SERVICE-3 validation bounds, and authorization
 * (Epic 04, AC-2, AC-3, AC-5).
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->staffUser = User::factory()->create();

    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->staffUser, ['role' => TeamRole::Staff->value]);

    app(CurrentTenant::class)->set($this->team);
});

test('the services page lists the team services for every member', function () {
    Service::factory()->create(['team_id' => $this->team->id, 'name' => 'Deep Tissue Massage']);

    $this->actingAs($this->staffUser)
        ->get(route('services.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee('Deep Tissue Massage');
});

test('an owner can create a service', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->set('name', 'Consultation')
        ->set('description', 'A first conversation.')
        ->set('durationMinutes', 45)
        ->set('bufferBeforeMinutes', 5)
        ->set('bufferAfterMinutes', 10)
        ->set('priceMinor', 2500)
        ->set('color', CalendarColor::Teal->value)
        ->call('saveService')
        ->assertHasNoErrors();

    expect(Service::query()->firstOrFail())
        ->name->toBe('Consultation')
        ->duration_minutes->toBe(45)
        ->buffer_before_minutes->toBe(5)
        ->buffer_after_minutes->toBe(10)
        ->price_minor->toBe(2500)
        ->color->toBe(CalendarColor::Teal)
        ->team_id->toBe($this->team->id);
});

test('an owner can update a service', function () {
    $service = Service::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->owner);

    Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->call('editService', $service->id)
        ->set('name', 'Renamed Service')
        ->set('durationMinutes', 60)
        ->call('saveService')
        ->assertHasNoErrors();

    expect($service->fresh())
        ->name->toBe('Renamed Service')
        ->duration_minutes->toBe(60);
});

test('duration bounds are enforced (FR-SERVICE-3)', function (int $duration, bool $accepted) {
    $this->actingAs($this->owner);

    $component = Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->set('name', 'Bounded')
        ->set('durationMinutes', $duration)
        ->call('saveService');

    if ($accepted) {
        $component->assertHasNoErrors();
        expect(Service::query()->where('duration_minutes', $duration)->exists())->toBeTrue();
    } else {
        $component->assertHasErrors('durationMinutes');
        expect(Service::query()->count())->toBe(0);
    }
})->with([
    'duration 4 rejected' => [4, false],
    'duration 5 accepted' => [5, true],
    'duration 480 accepted' => [480, true],
    'duration 481 rejected' => [481, false],
]);

test('buffer bounds are enforced (FR-SERVICE-3)', function (int $buffer, bool $accepted) {
    $this->actingAs($this->owner);

    $component = Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->set('name', 'Buffered')
        ->set('durationMinutes', 30)
        ->set('bufferBeforeMinutes', $buffer)
        ->set('bufferAfterMinutes', $buffer)
        ->call('saveService');

    if ($accepted) {
        $component->assertHasNoErrors();
        expect(Service::query()->where('buffer_before_minutes', $buffer)->exists())->toBeTrue();
    } else {
        $component->assertHasErrors(['bufferBeforeMinutes', 'bufferAfterMinutes']);
        expect(Service::query()->count())->toBe(0);
    }
})->with([
    'buffer -1 rejected' => [-1, false],
    'buffer 0 accepted' => [0, true],
    'buffer 120 accepted' => [120, true],
    'buffer 121 rejected' => [121, false],
]);

test('price bounds are enforced and a missing price is accepted (FR-SERVICE-3)', function (?int $price, bool $accepted) {
    $this->actingAs($this->owner);

    $component = Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->set('name', 'Priced')
        ->set('durationMinutes', 30)
        ->set('priceMinor', $price)
        ->call('saveService');

    if ($accepted) {
        $component->assertHasNoErrors();
        expect(Service::query()->firstOrFail()->price_minor)->toBe($price);
    } else {
        $component->assertHasErrors('priceMinor');
        expect(Service::query()->count())->toBe(0);
    }
})->with([
    'price -1 rejected' => [-1, false],
    'price null accepted' => [null, true],
    'price 0 accepted' => [0, true],
    'price above cap rejected' => [10000001, false],
]);

test('archiving keeps the record but removes it from bookable services', function () {
    $service = Service::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->owner);

    Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->call('archiveService', $service->id)
        ->assertHasNoErrors();

    expect($service->fresh()->is_active)->toBeFalse()
        ->and(Service::query()->count())->toBe(1)
        ->and(Service::query()->bookable()->count())->toBe(0);
});

test('archived services are hidden by default and shown with the filter', function () {
    Service::factory()->create(['team_id' => $this->team->id, 'name' => 'Active Offer']);
    Service::factory()->archived()->create(['team_id' => $this->team->id, 'name' => 'Old Offer']);

    $this->actingAs($this->owner);

    Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->assertSee('Active Offer')
        ->assertDontSee('Old Offer')
        ->set('showArchived', true)
        ->assertSee('Active Offer')
        ->assertSee('Old Offer');
});

test('an archived service can be restored', function () {
    $service = Service::factory()->archived()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->owner);

    Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->call('restoreService', $service->id)
        ->assertHasNoErrors();

    expect(Service::query()->bookable()->count())->toBe(1);
});

test('a freshly created service renders its archive trigger and modal together (BUG-001)', function () {
    $this->actingAs($this->owner);

    // Reproduce the QA path: create a service through the component, then
    // assert the new row's archive trigger and its modal are both present
    // in the same render. They are emitted from one loop, so a trigger can
    // never point at a modal that was not rendered.
    $component = Livewire::test('pages::services.index', ['current_team' => $this->team])
        ->set('name', 'QA Test Service')
        ->set('durationMinutes', 30)
        ->call('saveService')
        ->assertHasNoErrors();

    $newService = Service::query()->where('name', 'QA Test Service')->firstOrFail();
    $html = $component->html();

    $modalName = 'archive-service-'.$newService->id;
    $triggerCount = substr_count($html, "modal-show', { name: '".$modalName."'");
    $modalCount = substr_count($html, 'data-modal="'.$modalName.'"');

    expect($triggerCount)->toBe(1)
        ->and($modalCount)->toBe($triggerCount);
});

test('a staff-role member cannot create, update, or archive services', function () {
    $service = Service::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->staffUser);

    // A fresh component per action: a 403 response carries no snapshot to
    // continue from.
    $test = fn () => Livewire::test('pages::services.index', ['current_team' => $this->team]);

    $test()->call('openCreateForm')->assertForbidden();
    $test()->call('saveService')->assertForbidden();
    $test()->call('editService', $service->id)->assertForbidden();
    $test()->call('archiveService', $service->id)->assertForbidden();
    $test()->call('restoreService', $service->id)->assertForbidden();

    expect($service->fresh()->is_active)->toBeTrue()
        ->and(Service::query()->count())->toBe(1);
});
