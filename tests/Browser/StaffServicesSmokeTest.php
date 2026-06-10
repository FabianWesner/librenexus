<?php

use App\Enums\TeamRole;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->owner->switchTeam($this->team);

    $services = Service::factory()->count(2)->create(['team_id' => $this->team->id]);
    Staff::factory()->count(2)->create(['team_id' => $this->team->id])
        ->each(fn (Staff $staff) => $staff->services()->sync($services->pluck('id')));

    $this->actingAs($this->owner);
});

test('the staff list page is accessible and error free', function () {
    visit(route('staff.index', ['current_team' => $this->team->slug], absolute: false))
        ->assertSee('Staff')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the staff create modal is accessible and error free', function () {
    $page = visit(route('staff.index', ['current_team' => $this->team->slug], absolute: false))
        ->click('Add staff')
        ->assertSee('Add a staff member');

    // Headless rendering produces no animation frames, so the dialog's
    // open transition stays pending at opacity 0 and axe would treat the
    // whole modal as invisible. Jump the transition to its end state.
    $page->script('document.querySelectorAll("dialog[open]").forEach((dialog) => dialog.getAnimations({subtree: true}).forEach((animation) => { try { animation.finish() } catch (error) { animation.cancel() } }))');

    $page->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the services list page is accessible and error free', function () {
    visit(route('services.index', ['current_team' => $this->team->slug], absolute: false))
        ->assertSee('Services')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the service create modal is accessible and error free', function () {
    $page = visit(route('services.index', ['current_team' => $this->team->slug], absolute: false))
        ->click('Add service')
        ->assertSee('Add a service');

    // See the staff modal test: finish the frozen open transition so axe
    // audits the modal content as visible.
    $page->script('document.querySelectorAll("dialog[open]").forEach((dialog) => dialog.getAnimations({subtree: true}).forEach((animation) => { try { animation.finish() } catch (error) { animation.cancel() } }))');

    $page->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});
