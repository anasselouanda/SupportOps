<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->unsignedBigInteger('active_asset_id')
                ->nullable()
                ->storedAs('CASE WHEN ended_at IS NULL THEN asset_id ELSE NULL END')
                ->unique();
            $table->timestamps(6);
            $table->index(['asset_id', 'started_at', 'id']);
            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
    }
};
