<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Directorio
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'name'], 'ix_contacts_institution_id_name');
            $table->index('name', 'ix_contacts_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
