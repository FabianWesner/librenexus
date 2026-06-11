<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments with the database-level double-booking guarantee
     * (FR-BOOK-3, ADR-0003): a partial GiST exclusion constraint on the
     * buffered time range, applied only to time-reserving statuses, makes
     * overlapping bookings for one staff member impossible regardless of
     * the code path.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('buffered_starts_at');
            $table->timestampTz('buffered_ends_at');
            $table->text('notes')->nullable();
            $table->string('cancellation_token_hash', 64)->unique();
            $table->timestampTz('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'starts_at']);
            $table->index(['staff_id', 'starts_at']);
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (
                staff_id WITH =,
                tstzrange(buffered_starts_at, buffered_ends_at) WITH &&
            )
            WHERE (status IN ('pending', 'confirmed'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
