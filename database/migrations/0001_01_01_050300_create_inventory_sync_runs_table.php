<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inventario (Blessing)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('Pendiente');
            $table->string('trigger', 20)->default('manual');
            $table->string('source', 20)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index(['company_id', 'started_at'], 'ix_inventory_sync_runs_company_id_started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_sync_runs');
    }
};
