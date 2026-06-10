<?php

use App\Enums\TeamRole;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * N+1 guard for the authenticated list pages (NFR-PERF, Epic 04): the staff
 * and services index pages must issue a constant number of queries no
 * matter how many records exist, and stay within a small budget.
 */
const LIST_PAGE_QUERY_BUDGET = 12;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);

    $this->queries = 0;
    DB::listen(function () {
        $this->queries++;
    });
});

test('the staff list page query count does not grow with the number of staff', function () {
    Staff::factory()->create(['team_id' => $this->team->id]);
    $services = Service::factory()->count(3)->create(['team_id' => $this->team->id]);

    $this->queries = 0;

    $this->actingAs($this->owner)
        ->get(route('staff.index', ['current_team' => $this->team->slug]))
        ->assertOk();

    $withOneStaff = $this->queries;

    Staff::factory()->count(7)->create(['team_id' => $this->team->id])
        ->each(fn (Staff $staff) => $staff->services()->sync($services->pluck('id')));

    $this->queries = 0;

    $this->actingAs($this->owner)
        ->get(route('staff.index', ['current_team' => $this->team->slug]))
        ->assertOk();

    expect($this->queries)->toBe($withOneStaff)
        ->toBeLessThanOrEqual(LIST_PAGE_QUERY_BUDGET);
});

test('the services list page query count does not grow with the number of services', function () {
    Service::factory()->create(['team_id' => $this->team->id]);

    $this->queries = 0;

    $this->actingAs($this->owner)
        ->get(route('services.index', ['current_team' => $this->team->slug]))
        ->assertOk();

    $withOneService = $this->queries;

    Service::factory()->count(7)->create(['team_id' => $this->team->id]);

    $this->queries = 0;

    $this->actingAs($this->owner)
        ->get(route('services.index', ['current_team' => $this->team->slug]))
        ->assertOk();

    expect($this->queries)->toBe($withOneService)
        ->toBeLessThanOrEqual(LIST_PAGE_QUERY_BUDGET);
});

test('the team switcher resolves roles from the loaded pivot without extra queries', function () {
    // Two more teams so the switcher iterates several memberships.
    foreach (range(1, 2) as $i) {
        $team = Team::factory()->create();
        $team->members()->attach($this->owner, ['role' => TeamRole::Admin->value]);
    }

    $this->owner->refresh();

    $this->queries = 0;

    $userTeams = $this->owner->toUserTeams(includeCurrent: true);

    expect($userTeams)->toHaveCount(4)
        ->and($userTeams->firstWhere('slug', $this->team->slug)->role)->toBe(TeamRole::Owner->value)
        ->and($this->queries)->toBe(1);
});
