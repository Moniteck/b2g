<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Directorio Institucional y adjuntos por entidad.
 *
 * - documents: número o referencia del documento y descripción libre.
 * - document_versions: marca de versión firmada y vigencia de cada versión (historial de renovaciones).
 * - document_links: categoría, descripción y autor del adjunto en la entidad.
 * - Datos: categorías antiguas del directorio a las claves nuevas y versión 1 para los
 *   documentos que tenían archivo pero ninguna versión registrada.
 */
return new class extends Migration
{
    /** Categorías antiguas del directorio => claves nuevas (Document::CATEGORIES). */
    private const CATEGORY_MAP = [
        'RTN' => 'rtn',
        'DNI' => 'dni',
        'Solvencia' => 'solvencia_sar',
        'Escritura' => 'escritura',
        'Poder' => 'poder',
        'Registro' => 'otro',
        'Otro' => 'otro',
    ];

    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('reference_number', 100)->nullable()->after('name');
            $table->string('description', 500)->nullable()->after('reference_number');
            $table->index(['company_id', 'kind', 'expires_at'], 'ix_documents_company_id_kind_expires_at');
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->date('issued_at')->nullable()->after('hash');
            $table->date('expires_at')->nullable()->after('issued_at');
            $table->string('note', 255)->nullable()->after('expires_at');
            $table->timestamp('signed_at')->nullable()->after('note');
            $table->foreignId('signed_by')->nullable()->after('signed_at')
                ->constrained('users', indexName: 'fk_document_versions_users_signer')->nullOnDelete();
        });

        Schema::table('document_links', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('context');
            $table->string('description', 500)->nullable()->after('category');
            $table->foreignId('created_by')->nullable()->after('description')
                ->constrained('users', indexName: 'fk_document_links_users_creator')->nullOnDelete();
        });

        foreach (self::CATEGORY_MAP as $old => $new) {
            DB::table('documents')->where('kind', 'empresa')->where('category', $old)->update(['category' => $new]);
        }

        DB::table('documents')
            ->whereNotNull('path')
            ->whereNotExists(fn ($query) => $query->from('document_versions')->whereColumn('document_versions.document_id', 'documents.id'))
            ->orderBy('id')
            ->get()
            ->each(fn ($document) => DB::table('document_versions')->insert([
                'document_id' => $document->id,
                'version' => max(1, (int) $document->version),
                'path' => $document->path,
                'disk' => $document->disk ?: 'local',
                'original_filename' => $document->original_filename,
                'mime_type' => $document->mime_type,
                'size' => $document->size,
                'hash' => $document->hash,
                'issued_at' => $document->issued_at,
                'expires_at' => $document->expires_at,
                'uploaded_by' => $document->uploaded_by,
                'created_at' => $document->created_at,
            ]));
    }

    public function down(): void
    {
        Schema::table('document_links', function (Blueprint $table) {
            $table->dropForeign('fk_document_links_users_creator');
            $table->dropColumn(['category', 'description', 'created_by']);
        });

        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropForeign('fk_document_versions_users_signer');
            $table->dropColumn(['issued_at', 'expires_at', 'note', 'signed_at', 'signed_by']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('ix_documents_company_id_kind_expires_at');
            $table->dropColumn(['reference_number', 'description']);
        });
    }
};
