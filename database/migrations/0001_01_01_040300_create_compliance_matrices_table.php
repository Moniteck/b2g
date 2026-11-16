<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Licitaciones
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_matrices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['tender_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_matrices');
    }
};
