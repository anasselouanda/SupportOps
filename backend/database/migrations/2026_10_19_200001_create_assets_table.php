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
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->string('inventory_code', 50)->unique();
            $table->string('type', 50);
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->string('serial_number', 100)->nullable();
            $table->enum('status', ['available', 'assigned', 'maintenance', 'retired'])->default('available');
            $table->date('purchase_date')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'id']);
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
