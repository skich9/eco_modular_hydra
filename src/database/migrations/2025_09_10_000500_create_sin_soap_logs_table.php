<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		if (!Schema::hasTable('sin_soap_logs')) {
			Schema::create('sin_soap_logs', function (Blueprint $table) {
				$table->bigIncrements('id');
				$table->string('service', 100);
				$table->string('method', 100);
				$table->longText('request_xml')->nullable();
				$table->longText('response_xml')->nullable();
				$table->boolean('success')->default(false);
				$table->text('error')->nullable();
				$table->timestamp('created_at')->useCurrent();
			});
		}
	}

	public function down(): void
	{
		Schema::dropIfExists('sin_soap_logs');
	}
};
