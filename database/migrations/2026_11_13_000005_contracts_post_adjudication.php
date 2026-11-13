<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->after('tender_id')->constrained()->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->after('quote_id')->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->after('institution_id')->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('contact_id')->constrained('users')->nullOnDelete();
            $table->decimal('awarded_amount', 14, 2)->nullable()->after('amount');
            $table->date('signed_at')->nullable()->after('awarded_amount');
            $table->unsignedInteger('duration_days')->nullable()->after('signed_at');
            $table->boolean('duration_business_days')->default(false)->after('duration_days');
            $table->string('start_trigger')->default('Orden de inicio')->after('duration_business_days');
            $table->date('start_date')->nullable()->after('start_trigger');
            $table->date('end_date')->nullable()->after('start_date');
            $table->boolean('product_delivered')->default(false)->after('status');
            $table->string('official_in_charge')->nullable()->after('product_delivered');
            $table->timestamp('closed_at')->nullable()->after('official_in_charge');
            $table->index(['company_id', 'status']);
        });

        Schema::create('contract_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit')->default('Unidad');
            $table->date('due_date')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->string('status')->default('Pendiente')->index();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('Seguimiento');
            $table->text('description');
            $table->timestamp('occurred_at');
            $table->string('next_action')->nullable();
            $table->date('next_action_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_follow_ups');
        Schema::dropIfExists('contract_deliveries');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id');
            $table->dropConstrainedForeignId('opportunity_id');
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn([
                'awarded_amount', 'signed_at', 'duration_days', 'duration_business_days',
                'start_trigger', 'start_date', 'end_date', 'product_delivered',
                'official_in_charge', 'closed_at',
            ]);
        });
    }
};
