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
        Schema::create('draft_entries', function (Blueprint $table) {
            $table->id('draft_entry_id');
            $table->foreignId('draft_id')->constrained('drafts', 'draft_id')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('schedule_groups', 'group_id')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects', 'subject_id')->cascadeOnDelete();
            $table->foreignId('instructor_id')->constrained('instructors', 'instructor_id')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('sections', 'section_id')->cascadeOnDelete();
            $table->enum('status', ['planned','confirmed'])->default('planned');
            $table->timestamps();
            $table->unique(['draft_id','subject_id','instructor_id','section_id'], 'draft_entries_core_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draft_entries');
    }
};


