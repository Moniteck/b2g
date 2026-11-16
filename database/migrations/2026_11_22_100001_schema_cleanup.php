<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('business_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_index');
            $table->dropColumn('role');
        });

        DB::table('events')->whereNull('created_by')->whereNotNull('user_id')->update(['created_by' => DB::raw('user_id')]);
        DB::table('events')->where('completed', true)->whereNull('completed_at')->update(['completed_at' => DB::raw('updated_at')]);
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'completed']);
        });

        Schema::table('institutions', fn (Blueprint $table) => $table->dropColumn(['fleet_type', 'management_notes', 'modality']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('contact_user_id'));

        DB::table('contracts')->whereNull('closed_at')->whereNotNull('completed_at')->update(['closed_at' => DB::raw('completed_at')]);
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn(['official_in_charge', 'completed_at']));

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropColumn(['path', 'disk', 'hash', 'original_filename', 'mime_type', 'size', 'uploaded_by', 'required_for']);
        });

        DB::table('inventory_items')->whereNull('suggested_price')->update(['suggested_price' => DB::raw('price')]);
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('price'));

        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn('prepared_by'));
        Schema::table('training_progress', fn (Blueprint $table) => $table->dropColumn('score'));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('updated_at'));

        // Llaves foráneas guardadas como texto.
        Schema::table('quote_items', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_item_id')->nullable()->change();
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete();
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('renewal_owner_id')->nullable()->change();
            $table->foreign('renewal_owner_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('clients', fn (Blueprint $table) => $table->foreign('source_quote_id')->references('id')->on('quotes')->nullOnDelete());

        Schema::table('reminder_rules', fn (Blueprint $table) => $table->time('send_time')->default('07:00:00')->change());
        Schema::table('poa_entries', fn (Blueprint $table) => $table->renameColumn('partida', 'budget_line'));
    }
};
