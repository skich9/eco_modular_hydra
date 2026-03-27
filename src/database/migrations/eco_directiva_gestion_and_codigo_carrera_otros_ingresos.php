<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('eco_directiva_gestion')) {
			Schema::create('eco_directiva_gestion', function (Blueprint $table) {
				$table->id();
				$table->string('gestion', 30);
				$table->string('cod_pensum', 50)->default('');
				$table->string('numero_aut', 80);
				$table->string('tipo_facturacion', 80)->nullable();
				$table->string('descripcion', 200)->nullable();
				$table->unsignedBigInteger('num_fact_ini')->nullable();
				$table->unsignedBigInteger('num_fact_fin')->nullable();
				$table->boolean('activo')->default(true);
				$table->timestamps();
				$table->unique(['gestion', 'cod_pensum', 'numero_aut'], 'uk_eco_dir_gestion_pensum_aut');
			});
		}

		if (Schema::hasTable('otros_ingresos') && !Schema::hasColumn('otros_ingresos', 'codigo_carrera')) {
			Schema::table('otros_ingresos', function (Blueprint $table) {
				$table->string('codigo_carrera', 50)->nullable()->after('cod_pensum');
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasTable('otros_ingresos') && Schema::hasColumn('otros_ingresos', 'codigo_carrera')) {
			Schema::table('otros_ingresos', function (Blueprint $table) {
				$table->dropColumn('codigo_carrera');
			});
		}
		Schema::dropIfExists('eco_directiva_gestion');
	}
};
