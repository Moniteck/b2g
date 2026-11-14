<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->string('sector')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('status')->default('Activo')->index();
            $table->decimal('accumulated_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('collection_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->string('concept');
            $table->decimal('amount', 14, 2);
            $table->date('issued_at');
            $table->date('due_at')->index();
            $table->date('paid_at')->nullable();
            $table->string('status')->default('Pendiente')->index();
            $table->decimal('interest_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('process_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('process_number')->index();
            $table->string('name');
            $table->string('modality')->nullable();
            $table->decimal('estimated_amount', 14, 2)->default(0);
            $table->decimal('offered_amount', 14, 2)->nullable();
            $table->string('result')->nullable()->index();
            $table->string('cause')->nullable();
            $table->date('published_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('guarantees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('issuer')->nullable();
            $table->string('number')->nullable()->unique();
            $table->decimal('amount', 14, 2);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->index();
            $table->string('status')->default('Vigente')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->decimal('billed_amount', 14, 2)->default(0);
            $table->decimal('collected_amount', 14, 2)->default(0);
            $table->unsignedTinyInteger('physical_progress')->default(0);
            $table->string('status')->default('En ejecución')->index();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('institutional_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('relationship_type')->nullable();
            $table->date('last_contact_at')->nullable()->index();
            $table->date('next_contact_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('relation_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institutional_relation_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('description');
            $table->dateTime('occurred_at');
            $table->timestamps();
        });
        Schema::create('poa_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->decimal('budgeted_amount', 14, 2)->default(0);
            $table->decimal('executed_amount', 14, 2)->default(0);
            $table->date('estimated_date')->nullable()->index();
            $table->string('status')->default('Identificada')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('consortia', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('status')->default('Activo')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('consortium_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consortium_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('participation_percentage', 5, 2);
            $table->string('role')->nullable();
            $table->timestamps();
        });
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->json('institutions')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('budget_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('moved_at')->index();
            $table->string('type');
            $table->string('concept');
            $table->string('reference')->nullable()->unique();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel')->default('whatsapp');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('training_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tutorial_key');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'tutorial_key']);
        });
    }

    public function down(): void
    {
        foreach (['training_progress', 'notification_templates', 'budget_movements', 'competitors', 'consortium_members', 'consortia', 'poa_entries', 'relation_interactions', 'institutional_relations', 'contracts', 'guarantees', 'process_histories', 'collection_invoices', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
