<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant profile and booking policy (FR-TENANT-8). Defaults documented in
     * docs/assumptions.md; policy values are editable by owner/admin.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('timezone')->default('UTC');
            $table->string('contact_email')->nullable();
            $table->string('locale', 12)->default('en');
            $table->char('currency', 3)->default('EUR');

            $table->unsignedInteger('minimum_lead_time_minutes')->default(120);
            $table->unsignedInteger('booking_horizon_days')->default(60);
            $table->unsignedInteger('cancellation_cutoff_minutes')->default(120);
            $table->unsignedInteger('reminder_lead_time_hours')->default(24);
            $table->boolean('requires_approval')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'timezone',
                'contact_email',
                'locale',
                'currency',
                'minimum_lead_time_minutes',
                'booking_horizon_days',
                'cancellation_cutoff_minutes',
                'reminder_lead_time_hours',
                'requires_approval',
            ]);
        });
    }
};
