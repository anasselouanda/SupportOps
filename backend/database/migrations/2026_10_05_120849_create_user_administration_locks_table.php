<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_administration_locks')) {
            Schema::create('user_administration_locks', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
            });
        }

        DB::table('user_administration_locks')->insertOrIgnore(['id' => 1]);
    }

    /** Keep the shared lock available when additive migrations are rolled back. */
    public function down(): void {}
};
