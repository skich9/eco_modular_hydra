<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			// Nuevos flags de documento
			$table->char('tipo_documento', 1)->nullable()->after('id_forma_cobro'); // 'F' | 'R'
			$table->char('medio_doc', 1)->nullable()->after('tipo_documento'); // 'C' | 'M'

			// Índices útiles para consultas
			$table->index('fecha_cobro', 'idx_cobro_fecha_cobro');
			$table->index('cod_ceta', 'idx_cobro_cod_ceta');
			$table->index('nro_factura', 'idx_cobro_nro_factura');
			$table->index('nro_recibo', 'idx_cobro_nro_recibo');
		});
	}

	public function down(): void
	{
		Schema::table('cobro', function (Blueprint $table) {
			$table->dropIndex('idx_cobro_fecha_cobro');
			$table->dropIndex('idx_cobro_cod_ceta');
			$table->dropIndex('idx_cobro_nro_factura');
			$table->dropIndex('idx_cobro_nro_recibo');

			$table->dropColumn('tipo_documento');
			$table->dropColumn('medio_doc');
		});
	}
};
