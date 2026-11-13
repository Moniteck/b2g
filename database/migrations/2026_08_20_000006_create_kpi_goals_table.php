<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_goals', function (Blueprint $table) {
            $table->id();
            $table->string('metric')->index();
            $table->decimal('target', 14, 2);
            $table->date('period_start')->index();
            $table->date('period_end')->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['metric', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_goals');
    }
};
