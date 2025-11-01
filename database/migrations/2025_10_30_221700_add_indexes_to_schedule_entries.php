<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            // Common lookup for grouping schedule entries
            $table->index(['group_id', 'subject_id', 'section_id'], 'idx_entries_group_subject_section');
            $table->index(['section_id'], 'idx_entries_section_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropIndex('idx_entries_group_subject_section');
            $table->dropIndex('idx_entries_section_id');
        });
    }
};


