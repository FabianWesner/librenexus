<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A booking of one service with one staff member at one slot for one
 * customer (requirements.md glossary). The buffered range is what the
 * double-booking exclusion constraint guards (ADR-0003). The cancellation
 * token is stored only as a SHA-256 hash (SEC-TOKEN-1).
 *
 * @property int $id
 * @property int $team_id
 * @property int $staff_id
 * @property int $service_id
 * @property int $customer_id
 * @property AppointmentStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $buffered_starts_at
 * @property Carbon $buffered_ends_at
 * @property string|null $notes
 * @property string $cancellation_token_hash
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Staff $staff
 * @property-read Service $service
 * @property-read Customer $customer
 */
#[Fillable([
    'team_id',
    'staff_id',
    'service_id',
    'customer_id',
    'status',
    'starts_at',
    'ends_at',
    'buffered_starts_at',
    'buffered_ends_at',
    'notes',
    'cancellation_token_hash',
    'reminder_sent_at',
])]
class Appointment extends TenantModel
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * Get the staff member for this appointment.
     *
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Get the booked service.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the customer who booked.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Scope to appointments whose status reserves the staff member's time
     * (FR-APPT-4): exactly the rows the exclusion constraint applies to.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeReservingTime(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::reservingValues());
    }

    /**
     * Find an appointment by its raw manage token (SEC-TOKEN-1): the token
     * is hashed and looked up by exact index match, so no timing oracle
     * exists; the raw token is never stored.
     */
    public static function findByManageToken(string $rawToken): ?self
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('cancellation_token_hash', hash('sha256', $rawToken))
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'buffered_starts_at' => 'datetime',
            'buffered_ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }
}
