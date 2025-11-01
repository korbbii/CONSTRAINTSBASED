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
        Schema::table('schedule_entries', function (Blueprint $table) {
            // instructor_id moved to meetings; keep only subject/section on entries
            $table->foreignId('subject_id')->after('group_id')->constrained('subjects','subject_id');
            $table->foreignId('section_id')->after('subject_id')->constrained('sections','section_id');
            $table->enum('status', ['planned','confirmed'])->default('confirmed')->after('section_id');

            if (Schema::hasColumn('schedule_entries','instructor')) { $table->dropColumn('instructor'); }
            if (Schema::hasColumn('schedule_entries','subject_code')) { $table->dropColumn('subject_code'); }
            if (Schema::hasColumn('schedule_entries','subject_description')) { $table->dropColumn('subject_description'); }
            if (Schema::hasColumn('schedule_entries','unit')) { $table->dropColumn('unit'); }
            if (Schema::hasColumn('schedule_entries','day')) { $table->dropColumn('day'); }
            if (Schema::hasColumn('schedule_entries','start_time')) { $table->dropColumn('start_time'); }
            if (Schema::hasColumn('schedule_entries','end_time')) { $table->dropColumn('end_time'); }
            if (Schema::hasColumn('schedule_entries','block')) { $table->dropColumn('block'); }
            if (Schema::hasColumn('schedule_entries','year_level')) { $table->dropColumn('year_level'); }
            if (Schema::hasColumn('schedule_entries','employment_type')) { $table->dropColumn('employment_type'); }

            // Unique key no longer includes instructor_id
            $table->unique(['group_id','subject_id','section_id'], 'schedule_entries_unique_core');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropUnique('schedule_entries_unique_core');
            $table->dropConstrainedForeignId('subject_id');
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn('status');
        });
    }
};


