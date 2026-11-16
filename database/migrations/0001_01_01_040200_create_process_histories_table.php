<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Licitaciones
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->comment('Empresa del grupo dueña del registro')->constrained('companies')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('process_number');
            $table->string('name');
            $table->string('modality')->nullable();
            $table->string('category')->nullable();
            $table->boolean('participated')->default(false);
            $table->string('decision')->nullable();
            $table->decimal('estimated_amount', 14, 2)->default('0.00');
            $table->decimal('offered_amount', 14, 2)->nullable();
            $table->string('result')->nullable();
            $table->json('result_metadata')->nullable();
            $table->string('cause')->nullable();
            $table->date('published_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'closed_at'], 'ix_process_histories_company_id_closed_at');
            $table->index('process_number');
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_histories');
    }
};
