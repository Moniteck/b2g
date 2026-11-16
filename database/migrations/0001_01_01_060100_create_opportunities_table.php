<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comercial: pipeline, cotizaciones y clientes
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->foreignId('tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('opportunity_type')->nullable();
            $table->decimal('amount', 14, 2)->default('0.00');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('stage')->default('prospecto');
            $table->string('next_action')->nullable();
            $table->string('winner_name')->nullable();
            $table->decimal('awarded_amount', 14, 2)->nullable();
            $table->date('awarded_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->date('expected_close_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'ix_opportunities_company_id_created_at');
            $table->index(['company_id', 'owner_id'], 'ix_opportunities_company_id_owner_id');
            $table->index(['company_id', 'stage'], 'ix_opportunities_company_id_stage');
            $table->index('expected_close_date');
            $table->index('stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
