<?php

use App\Enums\TeamRole;
use App\Models\AvailabilityRule;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TimeOff;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['timezone' => 'Europe/Berlin']);
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);

    $this->staff = Staff::factory()->create(['team_id' => $this->team->id]);

    AvailabilityRule::factory()->window(1, '09:00', '12:00')->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staff->id,
    ]);

    TimeOff::factory()->create([
        'team_id' => $this->team->id,
        'staff_id' => $this->staff->id,
        'starts_at' => '2026-08-03 07:00:00',
        'ends_at' => '2026-08-07 16:00:00',
        'reason' => 'Summer vacation',
    ]);

    $this->actingAs($this->owner);
});

test('the availability editor is accessible and error free', function () {
    visit(route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->staff->id], absolute: false))
        ->assertSee('Weekly hours')
        ->assertSee('Europe/Berlin')
        ->assertSee('09:00 - 12:00')
        ->assertSee('Summer vacation')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('an availability window can be added through the UI', function () {
    visit(route('staff.availability', ['current_team' => $this->team->slug, 'staff' => $this->staff->id], absolute: false))
        ->select('@rule-start-input-2', '10:00')
        ->select('@rule-end-input-2', '14:00')
        ->click('@rule-add-button-2')
        ->assertSee('10:00 - 14:00');

    expect(AvailabilityRule::query()->withoutGlobalScopes()->where([
        'staff_id' => $this->staff->id,
        'weekday' => 2,
    ])->count())->toBe(1);
});
