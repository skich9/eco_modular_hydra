<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		if (!Schema::hasTable('doc_counter')) {
			Schema::create('doc_counter', function (Blueprint $table) {
				$table->string('scope', 150)->primary();
				$table->unsignedBigInteger('last')->default(0);
				$table->timestamps();
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('doc_counter');
	}
};
