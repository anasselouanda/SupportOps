<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_EMPLOYEE = 'employee';

    public const ROLE_TECHNICIAN = 'technician';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_EMPLOYEE, self::ROLE_TECHNICIAN, self::ROLE_ADMIN];

    protected $attributes = ['role' => self::ROLE_EMPLOYEE, 'is_active' => true];

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
            'password' => 'hashed',
            'role' => 'string',
            'is_active' => 'boolean',
        ];
    }

    public function isActiveAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMIN && $this->is_active;
    }

    /** @return array{id: int, name: string, email: string, role: string, is_active: bool} */
    public function publicData(): array
    {
        return $this->only(['id', 'name', 'email', 'role', 'is_active']);
    }
}
