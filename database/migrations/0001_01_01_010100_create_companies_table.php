<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Núcleo: empresas, usuarios y acceso
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('representative')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->string('blessing_base_url')->nullable();
            $table->text('blessing_token')->nullable();
            $table->string('blessing_price_list')->nullable();
            $table->decimal('tax_rate', 5, 2)->default('15.00');
            $table->boolean('prices_include_tax')->default(false);
            $table->timestamps();
            $table->unique('code');
            $table->index('is_active');
            $table->unique('tax_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
