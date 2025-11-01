<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedule_meetings', function (Blueprint $table) {
            // Speed up overlap checks by time window scoped to resource
            $table->index(['day', 'start_time', 'end_time', 'room_id'], 'idx_meetings_day_time_room');
            $table->index(['day', 'start_time', 'end_time', 'instructor_id'], 'idx_meetings_day_time_instructor');
            $table->index(['entry_id'], 'idx_meetings_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_meetings', function (Blueprint $table) {
            $table->dropIndex('idx_meetings_day_time_room');
            $table->dropIndex('idx_meetings_day_time_instructor');
            $table->dropIndex('idx_meetings_entry_id');
        });
    }
};


