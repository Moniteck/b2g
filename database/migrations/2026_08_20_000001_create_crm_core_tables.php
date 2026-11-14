<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('operator')->index();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id')->nullable()->unique();
            $table->string('representative')->nullable();
            $table->timestamps();
        });
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('acronym')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('potential')->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('stage')->default('prospecto')->index();
            $table->date('expected_close_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number')->unique();
            $table->date('quoted_at');
            $table->string('purchase_type');
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->string('status')->default('Borrador')->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit')->default('Unidad');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('margin', 5, 2)->default(45);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });
        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->string('purchase_type')->nullable();
            $table->string('status')->default('Activa')->index();
            $table->decimal('estimated_amount', 14, 2)->default(0);
            $table->date('closing_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('path')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->string('status')->default('Vigente')->index();
            $table->timestamps();
        });
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
        Schema::create('legal_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
        });
        Schema::create('legal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_messages');
        Schema::dropIfExists('legal_conversations');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('events');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('tenders');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('companies');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
