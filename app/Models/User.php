<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Message;
use App\Models\Notification as MarketNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'phone', 'supplier_id'])]
#[Hidden(['password', 'remember_token', 'pin_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Phone OTPs issued for the Market Intelligence and Exchange access gate.
     */
    public function phoneOtps(): HasMany
    {
        return $this->hasMany(PhoneOtp::class);
    }

    /**
     * Audit trail of access attempts to the Market Intelligence and Exchange module.
     */
    public function moduleAccessLogs(): HasMany
    {
        return $this->hasMany(ModuleAccessLog::class);
    }

    /**
     * Market Intelligence and Exchange notifications for this user.
     *
     * Named to avoid colliding with the Notifiable trait's own notifications() relation.
     */
    public function marketNotifications(): HasMany
    {
        return $this->hasMany(MarketNotification::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * The supplier profile this user represents, if any. Nullable — most of the spec's flows
     * assume a supplier/exporter user, but not every logged-in user is necessarily linked yet.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function savedRequirements(): HasMany
    {
        return $this->hasMany(SavedRequirement::class);
    }
}
