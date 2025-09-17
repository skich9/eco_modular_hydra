<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('doc_estudiante')) {
			Schema::create('doc_estudiante', function (Blueprint $table) {
				$table->string('nombre_doc', 100)->primary();
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('doc_estudiante');
	}
};
