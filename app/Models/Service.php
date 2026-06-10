<?php

namespace App\Models;

use App\Enums\CalendarColor;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * A bookable offering of a tenant (FR-SERVICE-1): duration, optional
 * buffers, and an optional price in minor units of the team currency.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property int $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int|null $price_minor
 * @property CalendarColor $color
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Staff> $staff
 */
#[Fillable([
    'team_id',
    'name',
    'description',
    'duration_minutes',
    'buffer_before_minutes',
    'buffer_after_minutes',
    'price_minor',
    'color',
    'is_active',
])]
class Service extends TenantModel
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * Get the staff members that can deliver this service (FR-STAFF-3).
     *
     * @return BelongsToMany<Staff, $this>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'service_staff');
    }

    /**
     * Scope to services open for booking (AC-3): archived services are
     * excluded from bookable data but their history stays intact.
     *
     * @param  Builder<Service>  $query
     */
    public function scopeBookable(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Format the price for display in the given tenant currency, or null
     * when the service has no price.
     */
    public function formattedPrice(string $currency): ?string
    {
        if ($this->price_minor === null) {
            return null;
        }

        return Number::currency($this->price_minor / 100, in: $currency) ?: null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'price_minor' => 'integer',
            'color' => CalendarColor::class,
            'is_active' => 'boolean',
        ];
    }
}
