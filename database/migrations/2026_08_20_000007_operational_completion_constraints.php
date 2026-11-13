<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('budget_movements', function (Blueprint $table) { $table->unique(['source_type', 'source_id']); }); }
    public function down(): void { Schema::table('budget_movements', function (Blueprint $table) { $table->dropUnique(['source_type', 'source_id']); }); }
};
