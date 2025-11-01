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
        Schema::create('schedule_groups', function (Blueprint $table) {
            $table->id('group_id');
            $table->string('department');
            $table->string('school_year');
            $table->enum('semester', ['1st Semester', '2nd Semester', 'Summer']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_groups');
    }
}; 