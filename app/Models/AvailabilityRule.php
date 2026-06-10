<?php

namespace App\Models;

use Database\Factories\AvailabilityRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A weekly recurring working-hours window for a staff member, expressed in
 * the tenant timezone (FR-AVAIL-1). Weekday is ISO: 1 = Monday .. 7 = Sunday.
 * End time "24:00" means end of day.
 *
 * @property int $id
 * @property int $team_id
 * @property int $staff_id
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Staff $staff
 */
#[Fillable(['team_id', 'staff_id', 'weekday', 'start_time', 'end_time'])]
class AvailabilityRule extends TenantModel
{
    /** @use HasFactory<AvailabilityRuleFactory> */
    use HasFactory;

    /**
     * Get the staff member this rule belongs to.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
