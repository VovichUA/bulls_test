<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('target_id')->constrained('sync_targets')->restrictOnDelete();
            $table->date('snapshot_date');
            $table->string('trigger_type', 16);
            $table->string('status', 16);
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedBigInteger('rows_skipped')->default(0);
            $table->unsignedBigInteger('rows_failed')->default(0);
            $table->longText('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->index(['target_id', 'snapshot_date', 'status']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
