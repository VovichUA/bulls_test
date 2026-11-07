<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_tabs', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('source_id')->constrained('sheet_sources')->cascadeOnDelete();
            $table->string('tab_name');
            $table->unsignedInteger('header_row')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unique(['source_id', 'tab_name']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_tabs');
    }
};
