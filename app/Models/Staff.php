<?php

namespace App\Models;

use App\Enums\CalendarColor;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bookable staff member of a tenant (FR-STAFF-1). Optionally linked to a
 * team membership (at most one staff record per membership, FR-STAFF-4).
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $membership_id
 * @property string $name
 * @property string|null $email
 * @property CalendarColor $color
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Membership|null $membership
 * @property-read Collection<int, Service> $services
 */
#[Fillable(['team_id', 'membership_id', 'name', 'email', 'color', 'is_active'])]
class Staff extends TenantModel
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'staff';

    /**
     * Get the team membership this staff record is linked to.
     *
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * Get the services this staff member can deliver (FR-STAFF-3).
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_staff');
    }

    /**
     * Get the weekly availability rules for this staff member (FR-AVAIL-1).
     *
     * @return HasMany<AvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /**
     * Get the time-off intervals for this staff member (FR-AVAIL-2).
     *
     * @return HasMany<TimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
    }

    /**
     * Scope to staff that can take bookings (AC-3): deactivated staff are
     * excluded from bookable data but their history stays intact.
     *
     * @param  Builder<Staff>  $query
     */
    public function scopeBookable(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color' => CalendarColor::class,
            'is_active' => 'boolean',
        ];
    }
}
