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
        // First, ensure reference_groups table exists
        if (!Schema::hasTable('reference_groups')) {
            Schema::create('reference_groups', function (Blueprint $table) {
                $table->id('group_id');
                $table->string('school_year');
                $table->string('education_level');
                $table->string('year_level');
                $table->timestamps();
                
                // Add indexes for better query performance
                $table->index(['school_year', 'education_level', 'year_level']);
                $table->unique(['school_year', 'education_level', 'year_level'], 'unique_reference_group');
            });
        }

        Schema::table('reference_schedules', function (Blueprint $table) {
            // Add group_id foreign key if it doesn't exist
            if (!Schema::hasColumn('reference_schedules', 'group_id')) {
                $table->unsignedBigInteger('group_id')->after('reference_id');
                $table->foreign('group_id')->references('group_id')->on('reference_groups')->onDelete('cascade');
                $table->index('group_id');
            }
            
            // Remove columns that are now in reference_groups table (if they exist)
            $columnsToRemove = [];
            if (Schema::hasColumn('reference_schedules', 'school_year')) $columnsToRemove[] = 'school_year';
            if (Schema::hasColumn('reference_schedules', 'education_level')) $columnsToRemove[] = 'education_level';
            if (Schema::hasColumn('reference_schedules', 'year_level')) $columnsToRemove[] = 'year_level';
            if (Schema::hasColumn('reference_schedules', 'block')) $columnsToRemove[] = 'block';
            
            if (!empty($columnsToRemove)) {
                $table->dropColumn($columnsToRemove);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reference_schedules', function (Blueprint $table) {
            // Drop foreign key and index first
            $table->dropForeign(['group_id']);
            $table->dropIndex(['group_id']);
            
            // Add back the columns that were removed
            $table->string('school_year')->after('reference_id');
            $table->string('education_level')->after('school_year');
            $table->string('year_level')->after('education_level');
            $table->string('block')->after('year_level');
            
            // Drop group_id column
            $table->dropColumn('group_id');
        });

        // Drop reference_groups table if it exists
        if (Schema::hasTable('reference_groups')) {
            Schema::dropIfExists('reference_groups');
        }
    }
};
