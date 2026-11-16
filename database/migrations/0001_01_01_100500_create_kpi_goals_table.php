<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Finanzas
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('metric');
            $table->decimal('target', 14, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('metric');
            $table->index('period_end');
            $table->index('period_start');
            $table->unique(['company_id', 'metric', 'period_start', 'period_end'], 'uq_kpi_goals_company_id_metric_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_goals');
    }
};
