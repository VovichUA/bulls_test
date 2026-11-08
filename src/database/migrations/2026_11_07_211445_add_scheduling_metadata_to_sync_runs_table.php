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
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->string('failure_kind', 32)->nullable();
            $table->char('config_fingerprint', 64)->nullable();
            $table->uuid('dispatch_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropIndex(['dispatch_id']);
            $table->dropColumn(['failure_kind', 'config_fingerprint', 'dispatch_id']);
        });
    }
};
