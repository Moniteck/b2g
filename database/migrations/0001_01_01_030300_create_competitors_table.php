<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Directorio
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->json('institutions')->nullable();
            $table->text('notes')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('category')->nullable();
            $table->decimal('average_award_price', 14, 2)->nullable();
            $table->date('last_participation_at')->nullable();
            $table->timestamps();
            $table->unique('name');
            $table->unique('tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
