<?php

namespace App\Models;

use Database\Factories\TimeOffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-off unavailability interval for a staff member, stored in UTC
 * (FR-AVAIL-2, ARCH-DATA-2).
 *
 * @property int $id
 * @property int $team_id
 * @property int $staff_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Staff $staff
 */
#[Fillable(['team_id', 'staff_id', 'starts_at', 'ends_at', 'reason'])]
class TimeOff extends TenantModel
{
    /** @use HasFactory<TimeOffFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'time_offs';

    /**
     * Get the staff member this interval belongs to.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
