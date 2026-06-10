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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // At most one staff record per membership (FR-STAFF-4). Removing
            // the membership unlinks the record but preserves its history.
            $table->foreignId('membership_id')->nullable()->unique()->constrained('team_members')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('color')->default('indigo');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
