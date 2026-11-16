<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Directorio
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('acronym')->nullable();
            $table->string('type')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('potential')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->default('Activa');
            $table->text('notes')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->index('acronym');
            $table->unique('name');
            $table->index('status');
            $table->index('potential', 'ix_institutions_potential');
            $table->index('type', 'ix_institutions_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
