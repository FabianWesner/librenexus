<?php

use App\Data\CurrentTenant;
use App\Enums\TeamRole;
use App\Models\AvailabilityRule;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use App\Models\User;
use Livewire\Livewire;

/**
 * Availability and time-off management with authorization (Epic 05, AC-1):
 * owners and admins manage every staff member; a staff-role member manages
 * exactly the staff record linked to their own membership (FR-STAFF-4).
 */
beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->staffUser = User::factory()->create();

    $this->team = Team::factory()->create(['timezone' => 'Europe/Berlin']);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->staffUser, ['role' => TeamRole::Staff->value]);

    $staffMembership = $this->team->memberships()->where('user_id', $this->staffUser->id)->firstOrFail();

    $this->ownStaff = Staff::factory()->linkedTo($staffMembership)->create(['name' => 'Linked Lina']);
    $this->otherStaff = Staff::factory()->create(['team_id' => $this->team->id, 'name' => 'Other Otto']);

    app(CurrentTenant::class)->set($this->team);
});

test('the availability editor renders with the staff name and the tenant timezone', function () {
    AvailabilityRule::factory()->window(1, '09:00', '12:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->otherStaff->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->otherStaff->id]))
        ->assertOk()
        ->assertSee('Other Otto')
        ->assertSee('Europe/Berlin')
        ->assertSee('09:00');
});

test('an admin can add an availability rule', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.2', '08:00')
        ->set('ruleEnd.2', '13:30')
        ->call('addRule', 2)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('availability_rules', [
        'team_id' => $this->team->id,
        'staff_id' => $this->otherStaff->id,
        'weekday' => 2,
        'start_time' => '08:00:00',
        'end_time' => '13:30:00',
    ]);
});

test('an admin can remove an availability rule', function () {
    $rule = AvailabilityRule::factory()->window(3, '09:00', '17:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->otherStaff->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->call('removeRule', $rule->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('availability_rules', ['id' => $rule->id]);
});

test('an admin can add and remove time off', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('timeOffStart', '2026-08-03T09:00')
        ->set('timeOffEnd', '2026-08-07T18:00')
        ->set('timeOffReason', 'Summer vacation')
        ->call('addTimeOff')
        ->assertHasNoErrors();

    $timeOff = TimeOff::query()->sole();

    expect($timeOff)
        ->staff_id->toBe($this->otherStaff->id)
        ->reason->toBe('Summer vacation');

    $component->call('removeTimeOff', $timeOff->id)->assertHasNoErrors();

    $this->assertDatabaseMissing('time_offs', ['id' => $timeOff->id]);
});

test('time off entered in the tenant timezone is stored as UTC', function () {
    $this->actingAs($this->admin);

    // Europe/Berlin is UTC+2 in July (CEST): 10:00 local is 08:00 UTC.
    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('timeOffStart', '2026-07-01T10:00')
        ->set('timeOffEnd', '2026-07-01T12:00')
        ->call('addTimeOff')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('time_offs', [
        'staff_id' => $this->otherStaff->id,
        'starts_at' => '2026-07-01 08:00:00',
        'ends_at' => '2026-07-01 10:00:00',
    ]);
});

test('a staff-role member manages the availability of their own linked record', function () {
    $this->actingAs($this->staffUser)
        ->get(route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->ownStaff->id]))
        ->assertOk();

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->ownStaff->id])
        ->set('ruleStart.5', '10:00')
        ->set('ruleEnd.5', '14:00')
        ->call('addRule', 5)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('availability_rules', [
        'staff_id' => $this->ownStaff->id,
        'weekday' => 5,
    ]);
});

test('a staff-role member cannot manage the availability of another staff member', function () {
    $this->actingAs($this->staffUser)
        ->get(route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->otherStaff->id]))
        ->assertForbidden();

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->assertForbidden();

    $this->assertDatabaseCount('availability_rules', 0);
});

test('a staff-role member only sees the availability link for their own record', function () {
    $ownUrl = route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->ownStaff->id]);
    $otherUrl = route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->otherStaff->id]);

    $this->actingAs($this->staffUser)
        ->get(route('staff.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee($ownUrl)
        ->assertDontSee($otherUrl);

    $this->actingAs($this->admin)
        ->get(route('staff.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertSee($ownUrl)
        ->assertSee($otherUrl);
});

test('a rule with the end before the start is rejected', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.2', '10:00')
        ->set('ruleEnd.2', '09:00')
        ->call('addRule', 2)
        ->assertHasErrors(['ruleEnd.2']);

    $this->assertDatabaseCount('availability_rules', 0);
});

test('an overlapping rule on the same weekday is rejected', function () {
    AvailabilityRule::factory()->window(3, '09:00', '12:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->otherStaff->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.3', '11:00')
        ->set('ruleEnd.3', '13:00')
        ->call('addRule', 3)
        ->assertHasErrors(['ruleStart.3']);

    $this->assertDatabaseCount('availability_rules', 1);
});

test('a touching rule on the same weekday is allowed', function () {
    AvailabilityRule::factory()->window(3, '09:00', '12:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->otherStaff->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.3', '12:00')
        ->set('ruleEnd.3', '15:00')
        ->call('addRule', 3)
        ->assertHasNoErrors();

    $this->assertDatabaseCount('availability_rules', 2);
});

test('a weekday outside Monday to Sunday is rejected', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.8', '09:00')
        ->set('ruleEnd.8', '12:00')
        ->call('addRule', 8)
        ->assertHasErrors(['weekday']);

    $this->assertDatabaseCount('availability_rules', 0);
});

test('a rule ending at 24:00 is accepted as end of day', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.6', '22:00')
        ->set('ruleEnd.6', '24:00')
        ->call('addRule', 6)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('availability_rules', [
        'staff_id' => $this->otherStaff->id,
        'weekday' => 6,
        'start_time' => '22:00:00',
        'end_time' => '24:00:00',
    ]);
});

test('a malformed rule time is rejected', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('ruleStart.1', '25:00')
        ->set('ruleEnd.1', 'noon')
        ->call('addRule', 1)
        ->assertHasErrors(['ruleStart.1', 'ruleEnd.1']);
});

test('time off with the end before the start is rejected', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::staff.availability', ['current_team' => $this->team, 'staff' => $this->otherStaff->id])
        ->set('timeOffStart', '2026-08-07T18:00')
        ->set('timeOffEnd', '2026-08-03T09:00')
        ->call('addTimeOff')
        ->assertHasErrors(['timeOffEnd']);

    $this->assertDatabaseCount('time_offs', 0);
});
