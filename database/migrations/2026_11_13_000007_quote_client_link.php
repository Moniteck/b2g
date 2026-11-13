<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotes', 'client_id')) {
            Schema::table('quotes', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotes', 'client_id')) {
            Schema::table('quotes', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
        }
    }
};
