<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['inventory_code', 'type', 'brand', 'model', 'serial_number', 'status', 'purchase_date'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    public const STATUSES = ['available', 'assigned', 'maintenance', 'retired'];

    public const METADATA_FIELDS = ['inventory_code', 'type', 'brand', 'model', 'serial_number', 'purchase_date'];

    protected $attributes = ['status' => 'available'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['purchase_date' => 'date'];
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /** @return HasOne<AssetAssignment, $this> */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->whereNull('ended_at');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->is_active || ! in_array($user->role, User::ROLES, true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role === User::ROLE_ADMIN) {
            return $query;
        }

        if ($user->role === User::ROLE_TECHNICIAN) {
            return $query->whereHas('tickets', function (Builder $tickets) use ($user): void {
                $tickets->where('technician_id', $user->getKey());
            });
        }

        return $query->whereHas('currentAssignment', function (Builder $assignments) use ($user): void {
            $assignments->where('user_id', $user->getKey());
        });
    }
}
