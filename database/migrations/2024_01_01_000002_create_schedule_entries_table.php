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
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id('entry_id');
            $table->foreignId('group_id')->constrained('schedule_groups', 'group_id')->onDelete('cascade');
            $table->string('instructor');
            $table->string('subject_code');
            $table->text('subject_description');
            $table->integer('unit');
            $table->string('day');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('block');
            $table->string('year_level');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
}; 