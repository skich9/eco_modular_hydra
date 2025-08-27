<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::table('descuentos', function (Blueprint $table) {
			if (!Schema::hasColumn('descuentos', 'cod_descuento')) {
				$table->unsignedBigInteger('cod_descuento')->nullable()->after('id_descuentos');
			}
			if (!Schema::hasColumn('descuentos', 'cod_beca')) {
				$table->unsignedBigInteger('cod_beca')->nullable()->after('cod_descuento');
			}

			$table->foreign('cod_descuento')->references('cod_descuento')->on('def_descuentos')->onDelete('restrict');
			$table->foreign('cod_beca')->references('cod_beca')->on('def_descuentos_beca')->onDelete('restrict');
		});
	}

	public function down(): void
	{
		Schema::table('descuentos', function (Blueprint $table) {
			// Drop foreign keys if exist
			try { $table->dropForeign(['cod_descuento']); } catch (\Throwable $e) {}
			try { $table->dropForeign(['cod_beca']); } catch (\Throwable $e) {}
			// Drop columns if exist
			if (Schema::hasColumn('descuentos', 'cod_beca')) {
				$table->dropColumn('cod_beca');
			}
			if (Schema::hasColumn('descuentos', 'cod_descuento')) {
				$table->dropColumn('cod_descuento');
			}
		});
	}
};
