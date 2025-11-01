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
        Schema::create('schedule_meetings', function (Blueprint $table) {
            $table->id('meeting_id');
            $table->foreignId('entry_id')->constrained('schedule_entries', 'entry_id')->cascadeOnDelete();
            // Instructor now belongs to each meeting
            $table->foreignId('instructor_id')->nullable()->constrained('instructors', 'instructor_id')->cascadeOnUpdate()->nullOnDelete();
            $table->enum('day', ['Mon','Tue','Wed','Thu','Fri','Sat']);
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('room_id')->constrained('rooms', 'room_id')->cascadeOnDelete();
            $table->enum('meeting_type', ['lecture','lab'])->default('lecture');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['instructor_id','day','start_time','end_time']);
            $table->index(['day','start_time','end_time','room_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_meetings');
    }
};


