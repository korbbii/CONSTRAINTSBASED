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
        Schema::table('rooms', function (Blueprint $table) {
            if (!Schema::hasColumn('rooms', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_lab');
            }
            $table->unique('room_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['room_name']);
            if (Schema::hasColumn('rooms', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};


