<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las extracciones pasan a ser el registro del análisis de una captura o de un
 * pliego: empresa, licitación, tipo, texto por página, consumo de la IA y
 * tiempos del trabajo en cola.
 *
 * - `company_id` y `tender_id` son nulables porque la tabla ya tenía filas sin
 *   ellos; toda fila nueva los lleva.
 * - `status`: queued, processing, review, confirmed, failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained('companies', 'id', 'fk_document_extractions_companies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tender_id')->nullable()->after('company_id')
                ->constrained('tenders', 'id', 'fk_document_extractions_tenders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('kind', 20)->default('pliego')->after('document_version_id');
            $table->json('pages')->nullable()->after('evidence');
            $table->json('usage')->nullable()->after('pages');
            $table->unsignedSmallInteger('attempts')->default(0)->after('usage');
            $table->dateTime('started_at')->nullable()->after('attempts');
            $table->dateTime('finished_at')->nullable()->after('started_at');
            $table->index(['tender_id', 'kind', 'status'], 'ix_document_extractions_tender_id_kind_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropIndex('ix_document_extractions_tender_id_kind_status');
            $table->dropForeign('fk_document_extractions_companies');
            $table->dropForeign('fk_document_extractions_tenders');
            $table->dropColumn(['company_id', 'tender_id', 'kind', 'pages', 'usage', 'attempts', 'started_at', 'finished_at']);
        });
    }
};
