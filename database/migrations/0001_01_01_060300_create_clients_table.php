<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comercial: pipeline, cotizaciones y clientes
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('source_quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('type')->nullable();
            $table->string('sector')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('status')->default('Activo');
            $table->decimal('accumulated_amount', 14, 2)->default('0.00');
            $table->date('last_purchase_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('origin')->default('Adjudicacion')->comment('Cómo nació el cliente: Adjudicacion, Importacion...');
            $table->string('dedupe_key')->nullable()->comment('empresa|institución|nombre normalizado; evita duplicar clientes al adjudicar');
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('tax_id');
            $table->index(['company_id', 'last_purchase_at'], 'ix_clients_company_id_last_purchase_at');
            $table->index(['company_id', 'name'], 'ix_clients_company_id_name');
            $table->index(['company_id', 'status'], 'ix_clients_company_id_status');
            $table->index('origin', 'ix_clients_origin');
            $table->unique(['company_id', 'tax_id'], 'uq_clients_company_id_tax_id');
            $table->unique('dedupe_key', 'uq_clients_dedupe_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
