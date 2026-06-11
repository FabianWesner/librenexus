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

test('a freshly created service can be archived through its confirmation modal (BUG-001)', function () {
    $page = visit(route('services.index', ['current_team' => $this->team->slug], absolute: false))
        ->click('Add service')
        ->assertSee('Add a service')
        ->fill('name', 'QA Test Service')
        ->fill('durationMinutes', '30')
        ->press('Save')
        ->assertSee('QA Test Service');

    $service = Service::query()->where('name', 'QA Test Service')->sole();

    // After the Livewire morph that added the new row, the archive trigger
    // and its modal must stay in sync: clicking archive on the new row has
    // to open the confirmation dialog (BUG-001 left it without a modal).
    $page->click('[data-test="service-row"]:has-text("QA Test Service") [data-test="service-archive-button"]')
        ->assertSee('Archive service')
        ->assertSee('QA Test Service will no longer be bookable');

    $page->script('document.querySelectorAll("dialog[open]").forEach((dialog) => dialog.getAnimations({subtree: true}).forEach((animation) => { try { animation.finish() } catch (error) { animation.cancel() } }))');

    // Each active row now owns its own modal, so scope the confirm click to
    // the open dialog rather than the (now several) archive-confirm buttons.
    $page->click('dialog[open] [data-test="service-archive-confirm"]')
        ->assertDontSee('QA Test Service will no longer be bookable');

    expect($service->fresh()->is_active)->toBeFalse();
});
