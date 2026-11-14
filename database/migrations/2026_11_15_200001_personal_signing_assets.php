<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma y sello pasan a ser personales (Fase 4).
 *
 * - `signing_assets`: el dueño es el usuario (`user_id`); como máximo un activo
 *   por usuario y tipo (`firma` | `sello`). Los activos por empresa se descartan
 *   (la base es de demostración y no tenía ninguno).
 * - `signature_applications`: registro de cada firma aplicada. Ya no depende del
 *   activo ni de autorizaciones previas; guarda una instantánea del firmante y
 *   del documento para que borrar la firma o el usuario no borre el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los archivos de los activos de empresa que hubiera se dejan en disco;
        // las filas se descartan junto con las tablas antiguas.
        Schema::dropIfExists('signature_applications');
        Schema::dropIfExists('signing_assets');

        Schema::create('signing_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->string('path');
            $table->string('disk', 20)->default('local');
            $table->string('mime_type', 50);
            $table->unsignedInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // Compatibilidad con consultas antiguas: un activo personal siempre está activo.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });

        Schema::create('signature_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name');
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
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
            // Solo para PDF firmados sueltos (sin documento del sistema).
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
        Schema::dropIfExists('signing_assets');

        Schema::create('signing_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('name');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('signature_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signing_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('page')->default(1);
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->decimal('rotation', 8, 2)->default(0);
            $table->string('document_hash')->nullable();
            $table->foreignId('authorization_id')->nullable()->constrained('mcp_authorizations')->nullOnDelete();
            $table->string('result_path')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['document_version_id', 'page']);
        });
    }
};
