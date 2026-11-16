<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Documentos y firmas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('tender_id')->nullable()->constrained('tenders')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->string('kind', 20)->default('pliego');
            $table->string('status')->default('Pendiente');
            $table->json('extracted')->nullable();
            $table->json('evidence')->nullable();
            $table->json('pages')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('method')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index(['tender_id', 'kind', 'status'], 'ix_document_extractions_tender_id_kind_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
