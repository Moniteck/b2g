<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de las herramientas B2G:
 *
 * - `legal_messages` no tenía marcas de tiempo: el chat las necesita para ordenar
 *   el historial y mostrar la hora de cada mensaje.
 * - `consortia.name` deja de ser único global y pasa a ser único por empresa.
 * - `generated_documents` gana `company_id`: el documento lleva el membrete de la
 *   empresa activa con la que se generó y solo se ve dentro de ella.
 * - `relation_interactions` guarda quién registró la interacción.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('legal_messages', 'created_at')) {
            Schema::table('legal_messages', function (Blueprint $table) {
                $table->timestamps();
                $table->index(['legal_conversation_id', 'created_at'], 'ix_legal_messages_conversation_id_created_at');
            });
        }

        $this->scopeConsortiumNameToCompany();

        if (! Schema::hasColumn('generated_documents', 'company_id')) {
            Schema::table('generated_documents', function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')
                    ->comment('Empresa con cuyo membrete se generó el documento')
                    ->constrained('companies', indexName: 'fk_generated_documents_companies')
                    ->nullOnDelete();
                $table->index(['company_id', 'user_id', 'created_at'], 'ix_generated_documents_company_id_user_id_created_at');
            });

            // Los documentos existentes quedan en la empresa por defecto de su autor.
            DB::table('generated_documents')->whereNull('company_id')->orderBy('id')->each(function ($document) {
                $companyId = DB::table('company_user')->where('user_id', $document->user_id)
                    ->orderByDesc('is_default')->orderBy('id')->value('company_id');

                if ($companyId !== null) {
                    DB::table('generated_documents')->where('id', $document->id)->update(['company_id' => $companyId]);
                }
            });
        }

        if (! Schema::hasColumn('relation_interactions', 'user_id')) {
            Schema::table('relation_interactions', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('institutional_relation_id')
                    ->comment('Usuario que registró la interacción')
                    ->constrained('users', indexName: 'fk_relation_interactions_users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('relation_interactions', 'user_id')) {
            Schema::table('relation_interactions', function (Blueprint $table) {
                $table->dropForeign('fk_relation_interactions_users');
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasColumn('generated_documents', 'company_id')) {
            Schema::table('generated_documents', function (Blueprint $table) {
                $table->dropForeign('fk_generated_documents_companies');
                $table->dropIndex('ix_generated_documents_company_id_user_id_created_at');
                $table->dropColumn('company_id');
            });
        }

        if ($this->hasIndex('consortia', 'uq_consortia_company_id_name')) {
            Schema::table('consortia', function (Blueprint $table) {
                $table->dropUnique('uq_consortia_company_id_name');
                $table->unique('name');
            });
        }

        if (Schema::hasColumn('legal_messages', 'created_at')) {
            Schema::table('legal_messages', function (Blueprint $table) {
                $table->dropIndex('ix_legal_messages_conversation_id_created_at');
                $table->dropTimestamps();
            });
        }
    }

    private function scopeConsortiumNameToCompany(): void
    {
        if ($this->hasIndex('consortia', 'uq_consortia_company_id_name')) {
            return;
        }

        $existing = collect(Schema::getIndexes('consortia'))
            ->first(fn (array $index) => $index['unique'] && ! $index['primary'] && $index['columns'] === ['name']);

        Schema::table('consortia', function (Blueprint $table) use ($existing) {
            if ($existing !== null) {
                $table->dropUnique($existing['name']);
            }

            $table->unique(['company_id', 'name'], 'uq_consortia_company_id_name');
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name);
    }
};
