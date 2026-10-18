<?php

namespace App\Models;

use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['reference', 'requester_id', 'technician_id', 'category_id', 'title', 'description', 'priority', 'status', 'resolved_at', 'closed_at', 'resolution_note'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    protected $attributes = ['priority' => 'normal', 'status' => 'open'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /** @return HasMany<TicketComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
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
            return $query->where(function (Builder $visible) use ($user): void {
                $visible->where('requester_id', $user->getKey())
                    ->orWhere('technician_id', $user->getKey());
            });
        }

        return $query->where('requester_id', $user->getKey());
    }
}
