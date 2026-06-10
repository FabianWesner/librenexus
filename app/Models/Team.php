<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property string $timezone
 * @property string|null $contact_email
 * @property string $locale
 * @property string $currency
 * @property int $minimum_lead_time_minutes
 * @property int $booking_horizon_days
 * @property int $cancellation_cutoff_minutes
 * @property int $reminder_lead_time_hours
 * @property bool $requires_approval
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 */
#[Fillable([
    'name',
    'slug',
    'is_personal',
    'timezone',
    'contact_email',
    'locale',
    'currency',
    'minimum_lead_time_minutes',
    'booking_horizon_days',
    'cancellation_cutoff_minutes',
    'reminder_lead_time_hours',
    'requires_approval',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     *
     * The slug is generated once at creation and stays stable on rename so
     * shared booking URLs never silently break (FR-BOOK-1); it only changes
     * when explicitly edited in the tenant settings (FR-TENANT-8).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Determine if the given user is the only owner of this team
     * (FR-TENANT-9: a team always keeps at least one owner).
     */
    public function isLastOwner(User $user): bool
    {
        $owners = $this->memberships()
            ->where('role', TeamRole::Owner->value)
            ->pluck('user_id');

        return $owners->count() === 1 && $owners->first() === $user->id;
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'minimum_lead_time_minutes' => 'integer',
            'booking_horizon_days' => 'integer',
            'cancellation_cutoff_minutes' => 'integer',
            'reminder_lead_time_hours' => 'integer',
            'requires_approval' => 'boolean',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
