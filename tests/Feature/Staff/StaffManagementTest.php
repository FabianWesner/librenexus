<?php

use App\Data\CurrentTenant;
use App\Enums\CalendarColor;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

/**
 * Staff CRUD, linking, and authorization (Epic 04, AC-1, AC-3, AC-5, AC-6).
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->staffUser = User::factory()->create();

    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->staffUser, ['role' => TeamRole::Staff->value]);

    app(CurrentTenant::class)->set($this->team);
});

test('the staff page lists the team staff for every member', function () {
    Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Erika Example']);

    $this->actingAs($this->staffUser)
        ->get(route('staff.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee('Erika Example');
});

test('an admin can create a staff member with assigned services', function () {
    $service = Service::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->set('name', 'Erika Example')
        ->set('email', 'erika@example.com')
        ->set('color', CalendarColor::Rose->value)
        ->set('serviceIds', [$service->id])
        ->call('saveStaff')
        ->assertHasNoErrors();

    $staff = Staff::query()->firstOrFail();

    expect($staff)
        ->name->toBe('Erika Example')
        ->email->toBe('erika@example.com')
        ->color->toBe(CalendarColor::Rose)
        ->is_active->toBeTrue()
        ->team_id->toBe($this->team->id);

    expect($staff->services()->pluck('services.id')->all())->toBe([$service->id]);
});

test('an owner can update a staff member and reassign services', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);
    $keep = Service::factory()->create(['team_id' => $this->team->id]);
    $drop = Service::factory()->create(['team_id' => $this->team->id]);
    $staff->services()->sync([$drop->id]);

    $this->actingAs($this->owner);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('editStaff', $staff->id)
        ->set('name', 'Renamed Staff')
        ->set('serviceIds', [$keep->id])
        ->call('saveStaff')
        ->assertHasNoErrors();

    expect($staff->fresh())
        ->name->toBe('Renamed Staff');

    expect($staff->services()->pluck('services.id')->all())->toBe([$keep->id]);
});

test('staff form input is validated server-side', function (string $field, mixed $value, ?string $errorKey = null) {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->set('name', 'Valid Name')
        ->set($field, $value)
        ->call('saveStaff')
        ->assertHasErrors($errorKey ?? $field);

    expect(Staff::query()->count())->toBe(0);
})->with([
    'missing name' => ['name', ''],
    'overlong name' => ['name', str_repeat('a', 256)],
    'invalid email' => ['email', 'not-an-email'],
    'unknown color' => ['color', 'magenta'],
    'foreign service' => ['serviceIds', [999999], 'serviceIds.0'],
]);

test('deactivating keeps the record but removes it from bookable staff', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('deactivateStaff', $staff->id)
        ->assertHasNoErrors();

    expect($staff->fresh()->is_active)->toBeFalse()
        ->and(Staff::query()->count())->toBe(1)
        ->and(Staff::query()->bookable()->count())->toBe(0);
});

test('a deactivated staff member can be reactivated', function () {
    $staff = Staff::factory()->inactive()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('reactivateStaff', $staff->id)
        ->assertHasNoErrors();

    expect(Staff::query()->bookable()->count())->toBe(1);
});

test('an admin can link a staff record to another member and unlink it again', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);
    $membership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();

    $this->actingAs($this->admin);

    $component = Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('editStaff', $staff->id)
        ->set('membershipId', $membership->id)
        ->call('saveStaff')
        ->assertHasNoErrors();

    expect($staff->fresh()->membership_id)->toBe($membership->id);

    $component
        ->call('editStaff', $staff->id)
        ->set('membershipId', null)
        ->call('saveStaff')
        ->assertHasNoErrors();

    expect($staff->fresh())
        ->membership_id->toBeNull()
        ->name->toBe($staff->name);
});

test('a staff-role member cannot create, update, link, or deactivate staff', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->staffUser);

    // A fresh component per action: a 403 response carries no snapshot to
    // continue from.
    $test = fn () => Livewire::test('pages::staff.index', ['current_team' => $this->team]);

    $test()->call('openCreateForm')->assertForbidden();
    $test()->call('saveStaff')->assertForbidden();
    $test()->call('editStaff', $staff->id)->assertForbidden();
    $test()->call('deactivateStaff', $staff->id)->assertForbidden();
    $test()->call('reactivateStaff', $staff->id)->assertForbidden();

    expect($staff->fresh()->is_active)->toBeTrue()
        ->and(Staff::query()->count())->toBe(1);
});

test('a user can never link a staff record to their own membership', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);
    $ownMembership = $this->team->memberships()->where('user_id', $this->admin->id)->firstOrFail();

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('editStaff', $staff->id)
        ->set('membershipId', $ownMembership->id)
        ->call('saveStaff')
        ->assertHasErrors('membershipId');

    expect($staff->fresh()->membership_id)->toBeNull();
});

test('a membership of another team cannot be linked', function () {
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);

    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->staffUser, ['role' => TeamRole::Staff->value]);
    $foreignMembership = $otherTeam->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('editStaff', $staff->id)
        ->set('membershipId', $foreignMembership->id)
        ->call('saveStaff')
        ->assertHasErrors('membershipId');

    expect($staff->fresh()->membership_id)->toBeNull();
});

test('a membership already linked to another staff record cannot be linked twice', function () {
    $membership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();
    Staff::factory()->linkedTo($membership)->create();
    $staff = Staff::factory()->create(['team_id' => $this->team->id]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->call('editStaff', $staff->id)
        ->set('membershipId', $membership->id)
        ->call('saveStaff')
        ->assertHasErrors('membershipId');

    expect($staff->fresh()->membership_id)->toBeNull();
});

test('only memberships without a staff record and not the actor are offered for linking', function () {
    $linkedMembership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();
    Staff::factory()->linkedTo($linkedMembership)->create();

    $this->actingAs($this->admin);

    $offered = Livewire::test('pages::staff.index', ['current_team' => $this->team])
        ->instance()
        ->linkableMemberships;

    $offeredIds = array_column($offered, 'id');

    $ownerMembership = $this->team->memberships()->where('user_id', $this->owner->id)->firstOrFail();
    $adminMembership = $this->team->memberships()->where('user_id', $this->admin->id)->firstOrFail();

    expect($offeredIds)->toContain($ownerMembership->id)
        ->not->toContain($adminMembership->id)
        ->not->toContain($linkedMembership->id);
});

test('removing a member from the team unlinks but preserves the staff record', function () {
    $membership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();
    $staff = Staff::factory()->linkedTo($membership)->create(['name' => 'History Keeper']);

    $this->actingAs($this->owner);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $this->team])
        ->set('memberId', $this->staffUser->id)
        ->call('removeMember');

    expect(Membership::query()->whereKey($membership->id)->exists())->toBeFalse();

    expect($staff->fresh())
        ->not->toBeNull()
        ->membership_id->toBeNull()
        ->name->toBe('History Keeper')
        ->team_id->toBe($this->team->id);
});
