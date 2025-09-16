<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	public function up(): void
	{
		// 1) Alinear tipo/longitud y permitir NULL para limpiar datos huérfanos antes de crear la FK
		Schema::table('items_cobro', function (Blueprint $table) {
			$table->string('actividad_economica', 25)->nullable()->change();
		});

		// 2) Saneamiento: cualquier valor que no exista en sin_actividades se pasa a NULL
		//    (evita violar la restricción al crear la FK)
		DB::statement(<<<SQL
UPDATE items_cobro ic
LEFT JOIN sin_actividades sa ON sa.codigo_caeb = ic.actividad_economica
SET ic.actividad_economica = NULL
WHERE sa.codigo_caeb IS NULL
SQL);

		// 3) Crear la FK
		Schema::table('items_cobro', function (Blueprint $table) {
			$table->foreign('actividad_economica')
				->references('codigo_caeb')
				->on('sin_actividades')
				->restrictOnDelete()
				->restrictOnUpdate();
		});
	}

	public function down(): void
	{
		Schema::table('items_cobro', function (Blueprint $table) {
			// Eliminar FK si existe
			try { $table->dropForeign(['actividad_economica']); } catch (\Throwable $e) {}
			// Revertir longitud al valor previo (255) y volver a NOT NULL como estaba originalmente
			$table->string('actividad_economica', 255)->nullable(false)->change();
		});
	}
};
