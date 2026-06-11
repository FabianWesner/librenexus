<?php

use App\Concerns\BelongsToTenant;
use App\Data\CurrentTenant;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Scopes\TenantScope;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/**
 * SEC-TENANT regression suite (AC-3): a member of tenant A must never read
 * or mutate tenant B data through any route, Livewire action, or query.
 * Tenant scoping is critical domain logic with elevated mutation targets
 * (test-plan.md), hence the covers() declaration.
 */
covers(TenantScope::class, BelongsToTenant::class, CurrentTenant::class);

beforeEach(function () {
    $this->ownerA = User::factory()->create();
    $this->teamA = Team::factory()->create(['name' => 'Tenant A']);
    $this->teamA->members()->attach($this->ownerA, ['role' => TeamRole::Owner->value]);

    $this->ownerB = User::factory()->create();
    $this->teamB = Team::factory()->create(['name' => 'Tenant B']);
    $this->teamB->members()->attach($this->ownerB, ['role' => TeamRole::Owner->value]);
});

test('a member of tenant A gets a 404 on tenant B dashboard', function () {
    $this->actingAs($this->ownerA)
        ->get(route('dashboard', ['current_team' => $this->teamB->slug]))
        ->assertNotFound();
});

test('a member of tenant A gets a 404 on tenant B settings page', function () {
    $this->actingAs($this->ownerA)
        ->get(route('teams.edit', $this->teamB))
        ->assertNotFound();
});

test('a member of tenant A cannot update a member role on tenant B', function () {
    $this->actingAs($this->ownerA);

    Livewire::test('pages::teams.edit', ['team' => $this->teamB])
        ->call('updateMember', $this->ownerB->id, TeamRole::Admin->value)
        ->assertForbidden();

    expect($this->ownerB->fresh()->teamRole($this->teamB))->toBe(TeamRole::Owner);
});

test('a member of tenant A cannot remove a member from tenant B', function () {
    $memberB = User::factory()->create();
    $this->teamB->members()->attach($memberB, ['role' => TeamRole::Staff->value]);

    $this->actingAs($this->ownerA);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $this->teamB])
        ->set('memberId', $memberB->id)
        ->call('removeMember')
        ->assertForbidden();

    expect($memberB->fresh()->belongsToTeam($this->teamB))->toBeTrue();
});

test('a member of tenant A cannot invite members into tenant B', function () {
    $this->actingAs($this->ownerA);

    Livewire::test('pages::teams.invite-member-modal', ['team' => $this->teamB])
        ->set('inviteEmail', 'intruder@example.com')
        ->set('inviteRole', TeamRole::Staff->value)
        ->call('createInvitation')
        ->assertForbidden();

    $this->assertDatabaseMissing('team_invitations', [
        'team_id' => $this->teamB->id,
        'email' => 'intruder@example.com',
    ]);
});

test('a member of tenant A cannot transfer ownership of tenant B', function () {
    $this->actingAs($this->ownerA);

    Livewire::test('pages::teams.transfer-ownership-modal', ['team' => $this->teamB])
        ->set('memberId', $this->ownerA->id)
        ->call('transferOwnership')
        ->assertForbidden();

    expect($this->ownerB->fresh()->teamRole($this->teamB))->toBe(TeamRole::Owner);
});

test('an invitation of tenant B cannot be revoked by the owner of tenant A', function () {
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $this->teamB->id,
        'invited_by' => $this->ownerB->id,
    ]);

    $this->actingAs($this->ownerA);

    Livewire::test('pages::teams.cancel-invitation-modal', ['team' => $this->teamB])
        ->set('invitationCode', $invitation->code)
        ->call('cancelInvitation')
        ->assertForbidden();

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

describe('tenant scope mechanism', function () {
    beforeEach(function () {
        Schema::create('tenant_scope_probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->string('name');
        });

        $this->probe = new class extends Model
        {
            use BelongsToTenant;

            public $timestamps = false;

            protected $table = 'tenant_scope_probes';

            protected $guarded = [];
        };

        $this->probe->newQuery()->withoutGlobalScopes()->getQuery()->insert([
            ['team_id' => $this->teamA->id, 'name' => 'a-record'],
            ['team_id' => $this->teamB->id, 'name' => 'b-record'],
        ]);
    });

    test('queries on a tenant-owned model only return rows of the active tenant', function () {
        app(CurrentTenant::class)->set($this->teamA);

        expect($this->probe->newQuery()->pluck('name')->all())->toBe(['a-record']);
    });

    test('queries on a tenant-owned model fail closed without a tenant context', function () {
        app(CurrentTenant::class)->clear();

        expect($this->probe->newQuery()->count())->toBe(0);
    });

    test('creating a tenant-owned model fills team_id from the active tenant', function () {
        app(CurrentTenant::class)->set($this->teamA);

        $record = $this->probe->newQuery()->create(['name' => 'auto-filled']);

        expect($record->getAttribute('team_id'))->toBe($this->teamA->id);
    });

    test('creating a tenant-owned model without a tenant context throws', function () {
        app(CurrentTenant::class)->clear();

        expect(fn () => $this->probe->newQuery()->create(['name' => 'orphan']))
            ->toThrow(RuntimeException::class, 'without an active tenant context');
    });

    test('a spoofed team_id for another tenant is rejected at creation', function () {
        app(CurrentTenant::class)->set($this->teamA);

        expect(fn () => $this->probe->newQuery()->create([
            'name' => 'spoofed',
            'team_id' => $this->teamB->id,
        ]))->toThrow(RuntimeException::class, 'other than the active one');

        expect($this->probe->newQuery()->withoutGlobalScopes()->where('name', 'spoofed')->count())->toBe(0);
    });

    test('an explicit team_id matching the active tenant is accepted', function () {
        app(CurrentTenant::class)->set($this->teamA);

        expect(app(CurrentTenant::class)->get()?->id)->toBe($this->teamA->id);

        $record = $this->probe->newQuery()->create([
            'name' => 'explicit-match',
            'team_id' => $this->teamA->id,
        ]);

        // Form input arrives as a string; a matching value must be accepted
        // regardless of scalar type.
        $stringMatch = $this->probe->newQuery()->create([
            'name' => 'string-match',
            'team_id' => (string) $this->teamA->id,
        ]);

        expect($record->getAttribute('team_id'))->toBe($this->teamA->id)
            ->and((int) $stringMatch->getAttribute('team_id'))->toBe($this->teamA->id);
    });

    test('the team relation resolves the owning tenant', function () {
        app(CurrentTenant::class)->set($this->teamA);

        $record = $this->probe->newQuery()->create(['name' => 'related']);

        expect($record->team()->withoutGlobalScopes()->first()?->id)->toBe($this->teamA->id);
    });

    test('trusted code may create with an explicit team_id and no context', function () {
        app(CurrentTenant::class)->clear();

        $record = $this->probe->newQuery()->create([
            'name' => 'factory-path',
            'team_id' => $this->teamB->id,
        ]);

        expect($record->getAttribute('team_id'))->toBe($this->teamB->id)
            ->and(app(CurrentTenant::class)->get())->toBeNull();
    });
});

describe('staff and services isolation (Epic 04)', function () {
    test('a member of tenant A gets a 404 on tenant B staff and services pages', function () {
        $this->actingAs($this->ownerA)
            ->get(route('staff.index', ['current_team' => $this->teamB->slug]))
            ->assertNotFound();

        $this->actingAs($this->ownerA)
            ->get(route('services.index', ['current_team' => $this->teamB->slug]))
            ->assertNotFound();
    });

    test('a member of tenant A cannot mount the staff or services page for tenant B', function () {
        $this->actingAs($this->ownerA);

        Livewire::test('pages::staff.index', ['current_team' => $this->teamB])
            ->assertForbidden();

        Livewire::test('pages::services.index', ['current_team' => $this->teamB])
            ->assertForbidden();
    });

    test('tenant B staff cannot be read, updated, or deactivated from tenant A', function () {
        $staffB = Staff::factory()->create(['team_id' => $this->teamB->id]);

        app(CurrentTenant::class)->set($this->teamA);
        $this->actingAs($this->ownerA);

        $component = fn () => Livewire::test('pages::staff.index', ['current_team' => $this->teamA]);

        // The tenant scope hides foreign records entirely, so reads and
        // mutations fail with a not-found (rendered as a 404 over HTTP).
        expect(fn () => $component()->call('editStaff', $staffB->id))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => $component()->call('deactivateStaff', $staffB->id))
            ->toThrow(ModelNotFoundException::class);

        expect($staffB->fresh()->is_active)->toBeTrue();
    });

    test('tenant B services cannot be read, updated, or archived from tenant A', function () {
        $serviceB = Service::factory()->create(['team_id' => $this->teamB->id]);

        app(CurrentTenant::class)->set($this->teamA);
        $this->actingAs($this->ownerA);

        $component = fn () => Livewire::test('pages::services.index', ['current_team' => $this->teamA]);

        expect(fn () => $component()->call('editService', $serviceB->id))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => $component()->call('archiveService', $serviceB->id))
            ->toThrow(ModelNotFoundException::class);

        expect($serviceB->fresh()->is_active)->toBeTrue();
    });

    test('a membership of tenant B cannot be linked to a staff record of tenant A', function () {
        $staffA = Staff::factory()->create(['team_id' => $this->teamA->id]);
        $membershipB = $this->teamB->memberships()->where('user_id', $this->ownerB->id)->firstOrFail();

        app(CurrentTenant::class)->set($this->teamA);
        $this->actingAs($this->ownerA);

        Livewire::test('pages::staff.index', ['current_team' => $this->teamA])
            ->call('editStaff', $staffA->id)
            ->set('membershipId', $membershipB->id)
            ->call('saveStaff')
            ->assertHasErrors('membershipId');

        expect($staffA->fresh()->membership_id)->toBeNull();
    });

    test('tenant B staff and services never appear in tenant A lists', function () {
        Staff::factory()->create(['team_id' => $this->teamA->id, 'name' => 'Alice Tenant-A-Staff']);
        Staff::factory()->create(['team_id' => $this->teamB->id, 'name' => 'Bob Tenant-B-Staff']);
        Service::factory()->create(['team_id' => $this->teamA->id, 'name' => 'Tenant-A-Service']);
        Service::factory()->create(['team_id' => $this->teamB->id, 'name' => 'Tenant-B-Service']);

        $this->actingAs($this->ownerA)
            ->get(route('staff.index', ['current_team' => $this->teamA->slug]))
            ->assertOk()
            ->assertSee('Alice Tenant-A-Staff')
            ->assertDontSee('Bob Tenant-B-Staff');

        $this->actingAs($this->ownerA)
            ->get(route('services.index', ['current_team' => $this->teamA->slug]))
            ->assertOk()
            ->assertSee('Tenant-A-Service')
            ->assertDontSee('Tenant-B-Service');
    });
});

describe('availability isolation (Epic 05)', function () {
    beforeEach(function () {
        $this->staffB = Staff::factory()->create(['team_id' => $this->teamB->id]);
    });

    test('a member of tenant A gets a 404 on the availability route of tenant B staff', function () {
        // Under tenant B's slug, membership fails first (SEC-TENANT-4).
        $this->actingAs($this->ownerA)
            ->get(route('staff.availability', ['current_team' => $this->teamB->slug, 'staff' => $this->staffB->id]))
            ->assertNotFound();

        // Under tenant A's own slug, the {staff} parameter is resolved in
        // mount through the tenant-scoped query (after the middleware set
        // the context), so the foreign record stays invisible and 404s.
        $this->actingAs($this->ownerA)
            ->get(route('staff.availability', ['current_team' => $this->teamA->slug, 'staff' => $this->staffB->id]))
            ->assertNotFound();
    });

    test('a member of tenant A cannot add rules or time off to tenant B staff via the component', function () {
        $this->actingAs($this->ownerA);

        // With tenant A's context, the foreign staff record is invisible to
        // the mount query (fail-closed tenant scope, rendered as 404).
        app(CurrentTenant::class)->set($this->teamA);

        expect(fn () => Livewire::test('pages::staff.availability', ['current_team' => $this->teamA, 'staff' => $this->staffB->id]))
            ->toThrow(ModelNotFoundException::class);

        // Even if the record resolves (defense in depth with tenant B's
        // context), the policy denies the non-member outright.
        app(CurrentTenant::class)->set($this->teamB);

        Livewire::test('pages::staff.availability', ['current_team' => $this->teamB, 'staff' => $this->staffB->id])
            ->assertForbidden();

        expect($this->staffB->availabilityRules()->withoutGlobalScopes()->count())->toBe(0)
            ->and($this->staffB->timeOff()->withoutGlobalScopes()->count())->toBe(0);
    });

    test('tenant B availability rules and time off never leak into tenant A queries', function () {
        $staffA = Staff::factory()->create(['team_id' => $this->teamA->id]);

        AvailabilityRule::factory()->window(1, '09:00', '12:00')->create([
            'team_id' => $this->teamA->id,
            'staff_id' => $staffA->id,
        ]);
        AvailabilityRule::factory()->window(2, '08:00', '18:00')->create([
            'team_id' => $this->teamB->id,
            'staff_id' => $this->staffB->id,
        ]);
        TimeOff::factory()->create(['team_id' => $this->teamB->id, 'staff_id' => $this->staffB->id]);

        app(CurrentTenant::class)->set($this->teamA);

        expect(AvailabilityRule::query()->pluck('staff_id')->all())->toBe([$staffA->id])
            ->and(TimeOff::query()->count())->toBe(0);
    });
});

describe('customers and appointments isolation (Epic 06)', function () {
    beforeEach(function () {
        $scaffold = function (Team $team, string $suffix): array {
            $staff = Staff::factory()->create(['team_id' => $team->id]);
            $service = Service::factory()->create(['team_id' => $team->id, 'name' => "Service {$suffix}"]);
            $customer = Customer::factory()->create(['team_id' => $team->id, 'name' => "Customer {$suffix}"]);

            $appointment = Appointment::factory()
                ->for($team, 'team')->for($staff, 'staff')->for($service, 'service')->for($customer, 'customer')
                ->create();

            return [$customer, $appointment];
        };

        [$this->customerA, $this->appointmentA] = $scaffold($this->teamA, 'Alpha');
        [$this->customerB, $this->appointmentB] = $scaffold($this->teamB, 'Beta');
    });

    test('tenant B customers and appointments never appear in tenant A queries', function () {
        app(CurrentTenant::class)->set($this->teamA);

        expect(Customer::query()->pluck('id')->all())->toBe([$this->customerA->id])
            ->and(Appointment::query()->pluck('id')->all())->toBe([$this->appointmentA->id]);
    });

    test('a direct findOrFail on tenant B records under tenant A context fails', function () {
        app(CurrentTenant::class)->set($this->teamA);

        expect(fn () => Customer::query()->findOrFail($this->customerB->id))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => Appointment::query()->findOrFail($this->appointmentB->id))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('appointment management isolation (Epic 07)', function () {
    beforeEach(function () {
        $staffB = Staff::factory()->create(['team_id' => $this->teamB->id]);
        $serviceB = Service::factory()->create(['team_id' => $this->teamB->id]);
        $customerB = Customer::factory()->create(['team_id' => $this->teamB->id]);

        $this->appointmentB = Appointment::factory()
            ->for($this->teamB, 'team')->for($staffB, 'staff')->for($serviceB, 'service')->for($customerB, 'customer')
            ->create();
    });

    test('a member of tenant A gets a 404 on tenant B appointment pages', function () {
        $this->actingAs($this->ownerA)
            ->get(route('appointments.index', ['current_team' => $this->teamB->slug]))
            ->assertNotFound();

        $this->actingAs($this->ownerA)
            ->get(route('calendar.index', ['current_team' => $this->teamB->slug]))
            ->assertNotFound();
    });

    test('a member of tenant A cannot mount the appointment pages for tenant B', function () {
        $this->actingAs($this->ownerA);

        Livewire::test('pages::appointments.index', ['current_team' => $this->teamB])
            ->assertForbidden();

        Livewire::test('pages::appointments.calendar', ['current_team' => $this->teamB])
            ->assertForbidden();
    });

    test('tenant B appointments cannot be viewed, transitioned, or rescheduled from tenant A', function () {
        app(CurrentTenant::class)->set($this->teamA);
        $this->actingAs($this->ownerA);

        $component = fn () => Livewire::test('pages::appointments.index', ['current_team' => $this->teamA]);

        // The tenant scope hides foreign records entirely, so reads and
        // mutations fail with a not-found (rendered as a 404 over HTTP).
        expect(fn () => $component()->call('openDetail', $this->appointmentB->id))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => $component()->call('transitionStatus', $this->appointmentB->id, 'cancelled'))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => $component()->call('openRescheduleModal', $this->appointmentB->id))
            ->toThrow(ModelNotFoundException::class);
        expect(fn () => $component()->call('openCancelModal', $this->appointmentB->id))
            ->toThrow(ModelNotFoundException::class);

        $untouched = $this->appointmentB->fresh();

        expect($untouched->status)->toBe($this->appointmentB->status)
            ->and($untouched->starts_at->equalTo($this->appointmentB->starts_at))->toBeTrue();
    });

    test('tenant B appointments never appear in tenant A list or calendar queries', function () {
        app(CurrentTenant::class)->set($this->teamA);
        $this->actingAs($this->ownerA);

        $listComponent = Livewire::test('pages::appointments.index', ['current_team' => $this->teamA])
            ->set('fromDate', '')
            ->set('untilDate', '');

        expect($listComponent->instance()->appointments)->toBeEmpty();

        $calendarComponent = Livewire::test('pages::appointments.calendar', ['current_team' => $this->teamA]);

        foreach ($calendarComponent->instance()->dayColumns as $column) {
            expect($column['blocks'])->toBeEmpty();
        }
    });
});

test('the tenant middleware is registered as Livewire persistent middleware', function () {
    // Guards the AppServiceProvider registration that re-establishes the
    // CurrentTenant context on Livewire update requests (SEC-TENANT).
    expect(Livewire::getPersistentMiddleware())
        ->toContain(EnsureTeamMembership::class);
});
