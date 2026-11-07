<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('column_mappings', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('target_id')->constrained('sync_targets')->cascadeOnDelete();
            $table->string('source_column');
            $table->string('target_column', 64);
            $table->string('data_type', 32);
            $table->json('type_options')->nullable();
            $table->boolean('nullable')->default(true);
            $table->string('format')->nullable();
            $table->unique(['target_id', 'target_column']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_mappings');
    }
};
