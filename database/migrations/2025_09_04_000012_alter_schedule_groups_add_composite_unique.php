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
        // REMOVED: Unique constraint on department/year/semester to allow multiple schedule versions
        // Users need to generate and compare different schedules for the same department/semester/year
        // Schema::table('schedule_groups', function (Blueprint $table) {
        //     $table->unique(['department','school_year','semester'], 'schedule_groups_dept_year_sem_unique');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // REMOVED: No longer adding constraint in up(), so nothing to drop in down()
        // Schema::table('schedule_groups', function (Blueprint $table) {
        //     $table->dropUnique('schedule_groups_dept_year_sem_unique');
        // });
    }
};


