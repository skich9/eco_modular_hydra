<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sin_cufd MODIFY COLUMN fecha_vigencia TIMESTAMP(3) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sin_cufd MODIFY COLUMN fecha_vigencia TIMESTAMP NULL');
    }
};
