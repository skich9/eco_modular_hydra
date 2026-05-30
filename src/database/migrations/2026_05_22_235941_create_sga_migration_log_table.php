<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sga_migration_log', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 60);
            $table->string('source_pk', 120);
            $table->string('dest_conn', 20);
            $table->string('dest_table', 60);
            $table->string('dest_pk', 120)->nullable();
            $table->enum('status', ['inserted', 'skipped', 'error']);
            $table->text('error_message')->nullable();
            $table->timestamp('pushed_at')->useCurrent();

            $table->index(['source_table', 'source_pk', 'dest_conn'], 'sml_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sga_migration_log');
    }
};
