<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Helper to check if an index exists on a table (MySQL-compatible)
        $indexExists = function (string $table, string $indexName): bool {
            $dbName = DB::getDatabaseName();
            $count = DB::table('information_schema.statistics')
                ->where('table_schema', $dbName)
                ->where('table_name', $table)
                ->where('index_name', $indexName)
                ->count();
            return $count > 0;
        };

        // schedule_meetings indexes: speed overlap checks and joins
        if (!$indexExists('schedule_meetings', 'idx_meetings_day_start_end')) {
            Schema::table('schedule_meetings', function ($table) {
                $table->index(['day', 'start_time', 'end_time'], 'idx_meetings_day_start_end');
            });
        }
        if (!$indexExists('schedule_meetings', 'idx_meetings_entry_id')) {
            Schema::table('schedule_meetings', function ($table) {
                $table->index(['entry_id'], 'idx_meetings_entry_id');
            });
        }
        if (!$indexExists('schedule_meetings', 'idx_meetings_instructor_id')) {
            Schema::table('schedule_meetings', function ($table) {
                $table->index(['instructor_id'], 'idx_meetings_instructor_id');
            });
        }
        if (!$indexExists('schedule_meetings', 'idx_meetings_room_id')) {
            Schema::table('schedule_meetings', function ($table) {
                $table->index(['room_id'], 'idx_meetings_room_id');
            });
        }

        // schedule_entries indexes: speed firstOrCreate lookup and hasConflict joins
        if (!$indexExists('schedule_entries', 'idx_entries_group_subject_section')) {
            Schema::table('schedule_entries', function ($table) {
                $table->index(['group_id', 'subject_id', 'section_id'], 'idx_entries_group_subject_section');
            });
        }
        if (!$indexExists('schedule_entries', 'idx_entries_group_subject')) {
            Schema::table('schedule_entries', function ($table) {
                $table->index(['group_id', 'subject_id'], 'idx_entries_group_subject');
            });
        }
        if (!$indexExists('schedule_entries', 'idx_entries_group_section')) {
            Schema::table('schedule_entries', function ($table) {
                $table->index(['group_id', 'section_id'], 'idx_entries_group_section');
            });
        }
    }

    public function down(): void
    {
        // Drop indexes if they exist (safe no-ops otherwise)
        Schema::table('schedule_meetings', function ($table) {
            $table->dropIndex('idx_meetings_day_start_end');
            $table->dropIndex('idx_meetings_entry_id');
            $table->dropIndex('idx_meetings_instructor_id');
            $table->dropIndex('idx_meetings_room_id');
        });

        Schema::table('schedule_entries', function ($table) {
            $table->dropIndex('idx_entries_group_subject_section');
            $table->dropIndex('idx_entries_group_subject');
            $table->dropIndex('idx_entries_group_section');
        });
    }
};


