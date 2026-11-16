<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Herramientas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutional_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('relationship_type')->nullable();
            $table->date('last_contact_at')->nullable();
            $table->date('next_contact_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('influence')->nullable();
            $table->string('channel')->nullable();
            $table->string('status')->default('Activa');
            $table->date('started_at')->nullable();
            $table->string('poa_interest')->nullable();
            $table->timestamps();
            $table->index('last_contact_at');
            $table->index('next_contact_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutional_relations');
    }
};
