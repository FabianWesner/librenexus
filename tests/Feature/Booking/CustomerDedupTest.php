<?php

use App\Actions\Booking\BookAppointment;
use App\Data\BookingRequest;
use App\Data\CurrentTenant;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * FR-CUST-2 / AC-1b: a customer is identified per tenant by
 * case-insensitive email; repeat bookings reuse and update the record,
 * other tenants get their own record.
 */
covers(BookAppointment::class, Customer::class);

/**
 * @return array{team: Team, staff: Staff, service: Service}
 */
function scaffoldBookableTenant(): array
{
    $team = Team::factory()->create(['timezone' => 'UTC']);
    $staff = Staff::factory()->create(['team_id' => $team->id]);
    $service = Service::factory()->create([
        'team_id' => $team->id,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
    ]);
    $service->staff()->attach($staff);

    AvailabilityRule::factory()->window(1, '09:00', '17:00')->create([
        'team_id' => $team->id,
        'staff_id' => $staff->id,
    ]);

    return ['team' => $team, 'staff' => $staff, 'service' => $service];
}

/**
 * @param  array{team: Team, staff: Staff, service: Service}  $tenant
 */
function bookFor(array $tenant, CarbonImmutable $startsAt, string $email, string $name = 'Alice Example', ?string $phone = null): void
{
    app(CurrentTenant::class)->set($tenant['team']);

    app(BookAppointment::class)->handle($tenant['team'], new BookingRequest(
        serviceId: $tenant['service']->id,
        staffId: $tenant['staff']->id,
        startsAt: $startsAt,
        customerName: $name,
        customerEmail: $email,
        customerPhone: $phone,
        notes: null,
    ));
}

beforeEach(function () {
    // 2027-03-08 is a Monday; "now" is the preceding Friday.
    $this->slotStart = CarbonImmutable::parse('2027-03-08T09:00:00', 'UTC');
    $this->travelTo($this->slotStart->subDays(3));

    $this->tenantA = scaffoldBookableTenant();
});

test('a repeat booking with the same email reuses the customer and updates name and phone', function () {
    bookFor($this->tenantA, $this->slotStart, 'alice@example.com', 'Alice Example', '+49 30 1111');
    bookFor($this->tenantA, $this->slotStart->addHours(2), 'alice@example.com', 'Alice Renamed', '+49 30 2222');

    app(CurrentTenant::class)->set($this->tenantA['team']);

    $customer = Customer::query()->sole();

    expect($customer->name)->toBe('Alice Renamed')
        ->and($customer->phone)->toBe('+49 30 2222')
        ->and($customer->appointments()->count())->toBe(2);
});

test('the same email in a different tenant is a separate customer record', function () {
    $tenantB = scaffoldBookableTenant();

    bookFor($this->tenantA, $this->slotStart, 'alice@example.com');
    bookFor($tenantB, $this->slotStart, 'alice@example.com');

    $records = Customer::query()
        ->withoutGlobalScopes()
        ->where('email', 'alice@example.com')
        ->get();

    expect($records)->toHaveCount(2)
        ->and($records->pluck('team_id')->sort()->values()->all())
        ->toBe(collect([$this->tenantA['team']->id, $tenantB['team']->id])->sort()->values()->all());
});

test('email matching is case-insensitive within a tenant', function () {
    bookFor($this->tenantA, $this->slotStart, 'Alice@X-Ample.com');
    bookFor($this->tenantA, $this->slotStart->addHours(2), 'alice@x-ample.com');

    app(CurrentTenant::class)->set($this->tenantA['team']);

    $customer = Customer::query()->sole();

    expect($customer->email)->toBe('alice@x-ample.com');
});
