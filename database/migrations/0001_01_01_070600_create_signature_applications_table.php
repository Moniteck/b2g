<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Documentos y firmas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('source_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('document_versions')->nullOnDelete();
            $table->string('document_name');
            $table->boolean('used_signature')->default(false);
            $table->boolean('used_seal')->default(false);
            $table->boolean('all_pages')->default(false);
            $table->json('pages');
            $table->json('placements');
            $table->unsignedInteger('page_count');
            $table->string('source_hash', 64)->nullable();
            $table->string('result_hash', 64)->nullable();
            $table->string('result_path')->nullable();
            $table->string('result_disk', 20)->nullable();
            $table->boolean('normalized')->default(false);
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'applied_at']);
            $table->index(['document_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_applications');
    }
};
