<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Herramientas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->comment('Empresa con cuyo membrete se generó el documento')->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('recipient');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'created_at'], 'ix_generated_documents_company_id_user_id_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
