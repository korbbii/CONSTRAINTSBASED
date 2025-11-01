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
        Schema::create('reference_schedules', function (Blueprint $table) {
            $table->id('reference_id');
            $table->string('school_year');
            $table->string('education_level');
            $table->string('year_level');
            $table->string('block');
            $table->string('time');
            $table->enum('day', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);
            $table->string('room');
            $table->string('instructor');
            $table->timestamps();
            
            // Add indexes for better query performance
            $table->index(['school_year', 'education_level']);
            $table->index(['day', 'time']);
            $table->index(['room', 'day', 'time']);
            $table->index(['instructor', 'day', 'time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_schedules');
    }
};
