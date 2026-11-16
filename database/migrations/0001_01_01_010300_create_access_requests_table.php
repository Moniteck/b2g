<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Núcleo: empresas, usuarios y acceso
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('position', 120);
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('company_name', 160)->nullable();
            $table->string('password');
            $table->string('status', 20)->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('approved_role', 20)->nullable();
            $table->timestamps();
            $table->index('email');
            $table->index(['status', 'company_id'], 'access_requests_status_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
