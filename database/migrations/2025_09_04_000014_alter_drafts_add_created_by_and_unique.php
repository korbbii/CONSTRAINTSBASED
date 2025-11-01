<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('drafts','draft_name')) {
                $table->string('draft_name')->nullable()->after('group_id');
            }
            if (!Schema::hasColumn('drafts','created_by')) {
                $table->foreignId('created_by')->nullable()->after('draft_name')->constrained('users','id')->nullOnDelete();
            }
            $table->unique(['group_id','draft_name'], 'drafts_group_draft_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            if (Schema::hasColumn('drafts','created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
        
        // Use raw SQL to drop the unique index safely
        DB::statement('ALTER TABLE drafts DROP INDEX drafts_group_draft_name_unique');
    }
};


