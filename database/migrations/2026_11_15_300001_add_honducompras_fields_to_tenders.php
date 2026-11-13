<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de la ficha de expediente de HonduCompras y del pliego.
 *
 * Todos son opcionales: una licitación creada a mano puede no tenerlos, y
 * «sin valor» significa «aún no se conoce» (no cero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->string('purchasing_unit')->nullable()->after('institution_id');
            $table->dateTime('started_at')->nullable()->after('published_at');
            $table->string('funding_source_type', 120)->nullable()->after('purchase_type');
            $table->string('funding_source', 120)->nullable()->after('funding_source_type');
            $table->string('honducompras_stage', 120)->nullable()->after('funding_source');
            $table->string('acquisition_type', 120)->nullable()->after('honducompras_stage');
            $table->string('offer_reception_place', 500)->nullable()->after('opening_at');
            $table->decimal('bid_document_fee', 14, 2)->nullable()->after('estimated_amount');
            $table->unsignedSmallInteger('offer_validity_days')->nullable()->after('bid_document_fee');
        });

        // El «Objeto» de HonduCompras suele pasar de 255 caracteres.
        Schema::table('tenders', function (Blueprint $table) {
            $table->string('title', 1000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->dropColumn([
                'purchasing_unit', 'started_at', 'funding_source_type', 'funding_source',
                'honducompras_stage', 'acquisition_type', 'offer_reception_place',
                'bid_document_fee', 'offer_validity_days',
            ]);
        });
    }
};
