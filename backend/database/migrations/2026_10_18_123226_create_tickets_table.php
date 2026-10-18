<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'id']);
            $table->index(['requester_id', 'created_at', 'id']);
            $table->index(['technician_id', 'created_at', 'id']);
            $table->index(['status', 'created_at', 'id']);
            $table->index(['priority', 'created_at', 'id']);
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
