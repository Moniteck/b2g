<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Herramientas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consortia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('Activo');
            $table->text('notes')->nullable();
            $table->foreignId('tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('leader_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->decimal('amount', 14, 2)->default('0.00');
            $table->date('formed_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->unique(['company_id', 'name'], 'uq_consortia_company_id_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consortia');
    }
};
