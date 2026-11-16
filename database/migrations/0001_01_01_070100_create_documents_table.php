<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Documentos y firmas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('name');
            $table->string('reference_number', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('kind')->default('proceso');
            $table->string('owner_name')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sealed_at')->nullable();
            $table->foreignId('renewal_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status')->default('Vigente');
            $table->date('issued_at')->nullable();
            $table->timestamps();
            $table->index('expires_at');
            $table->index(['kind', 'category', 'status']);
            $table->index('kind');
            $table->index(['status', 'expires_at']);
            $table->index('status');
            $table->index(['company_id', 'expires_at'], 'ix_documents_company_id_expires_at');
            $table->index(['company_id', 'kind', 'category'], 'ix_documents_company_id_kind_category');
            $table->index(['company_id', 'kind', 'expires_at'], 'ix_documents_company_id_kind_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
