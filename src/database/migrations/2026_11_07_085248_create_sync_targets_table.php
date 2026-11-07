<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_targets', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('tab_id')->constrained('sheet_tabs')->cascadeOnDelete();
            $table->string('target_table', 64);
            $table->json('unique_keys')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['tab_id', 'target_table']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_targets');
    }
};
