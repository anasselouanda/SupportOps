<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role')->default(User::ROLE_EMPLOYEE);
            });
        }

        if (! Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
            });
        }

        DB::table('users')
            ->where(fn (Builder $query) => $query->whereNull('role')->orWhere('role', ''))
            ->update(['role' => User::ROLE_EMPLOYEE]);
    }

    /** Preserve existing columns and user data; this additive migration is intentionally irreversible. */
    public function down(): void {}
};
