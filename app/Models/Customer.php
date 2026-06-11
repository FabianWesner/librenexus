<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A tenant-owned customer (FR-CUST-1..4): identified by case-insensitive
 * email, unique per tenant, never an authenticated user. Email is stored
 * lowercased so the per-tenant unique index is case-insensitive.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Appointment> $appointments
 */
#[Fillable(['team_id', 'name', 'email', 'phone'])]
class Customer extends TenantModel
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Get the customer's appointments.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Email is always stored lowercased (FR-CUST-2).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Str::lower(trim($value)),
        );
    }
}
