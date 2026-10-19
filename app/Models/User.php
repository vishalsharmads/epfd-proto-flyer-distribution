<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ADMIN_ROLES = ['super_admin', 'operations', 'finance', 'support'];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'otp_hash'];

    /** Emails are stored lowercased and trimmed so sign-in, reset and uniqueness ignore letter case. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_distributor' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(self::ADMIN_ROLES);
    }
}
