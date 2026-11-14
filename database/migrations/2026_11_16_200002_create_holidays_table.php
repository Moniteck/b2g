<?php

use App\Services\Contracts\HondurasHolidays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feriados por empresa para contar plazos en días hábiles (lunes a viernes sin feriados).
 *
 * Se siembran los feriados nacionales de Honduras de 2026 y 2027 en cada empresa existente;
 * las empresas nuevas los reciben al abrir la modal «Feriados» o al calcular un plazo
 * (`HolidayCalendar::ensureSeeded`). Las fechas que dependen de un decreto anual (Semana
 * Morazánica) quedan marcadas con `is_pending_review` hasta que un administrador las confirme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_holidays_companies')
                ->cascadeOnDelete(); // detalle de la empresa: se va con ella
            $table->date('holiday_date')->comment('Día no hábil');
            $table->string('name', 150);
            $table->boolean('is_pending_review')->default(false)->comment('Fecha estimada: confirmar con el decreto del año');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'fk_holidays_created_by_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'fk_holidays_updated_by_users')->nullOnDelete();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users', 'id', 'fk_holidays_deleted_by_users')->nullOnDelete();

            $table->index(['company_id', 'holiday_date', 'deleted_at'], 'ix_holidays_company_id_holiday_date');
            $table->comment('Feriados por empresa para el cálculo de plazos en días hábiles');
        });

        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            DB::table('holidays')->insert(array_map(fn (array $holiday) => $holiday + [
                'company_id' => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ], HondurasHolidays::defaults()));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
