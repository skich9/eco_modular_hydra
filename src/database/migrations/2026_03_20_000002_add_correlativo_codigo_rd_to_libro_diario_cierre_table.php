<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correlativo global NNN (mín. 3 dígitos) y código completo único RD-[CARRERA]-[MM]-[NNN].
     */
    public function up(): void
    {
        if (! Schema::hasTable('libro_diario_cierre')) {
            return;
        }

        Schema::table('libro_diario_cierre', function (Blueprint $table) {
            if (! Schema::hasColumn('libro_diario_cierre', 'correlativo')) {
                $table->unsignedInteger('correlativo')->nullable()->after('hora_cierre');
            }
            if (! Schema::hasColumn('libro_diario_cierre', 'codigo_rd')) {
                $table->string('codigo_rd', 80)->nullable()->after('correlativo');
            }
        });

        $this->backfillCorrelativos();

        Schema::table('libro_diario_cierre', function (Blueprint $table) {
            if (Schema::hasColumn('libro_diario_cierre', 'codigo_rd')) {
                $table->unique('codigo_rd');
            }
        });
    }

    private function backfillCorrelativos(): void
    {
        $rows = DB::table('libro_diario_cierre')->whereNull('correlativo')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $seq = (int) DB::table('libro_diario_cierre')->max('correlativo');
        if ($seq < 1) {
            $seq = 1;
        } else {
            $seq++;
        }

        foreach ($rows as $r) {
            $fecha = (string) ($r->fecha ?? '');
            $mes = strlen($fecha) >= 10 ? substr($fecha, 5, 2) : date('m');
            $car = isset($r->codigo_carrera) && trim((string) $r->codigo_carrera) !== ''
                ? substr(trim((string) $r->codigo_carrera), 0, 50)
                : 'S/N';

            while (true) {
                $pad = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
                $codigoRd = 'RD-' . $car . '-' . $mes . '-' . $pad;
                $taken = DB::table('libro_diario_cierre')
                    ->where('codigo_rd', $codigoRd)
                    ->where('id', '!=', $r->id)
                    ->exists();
                if (! $taken) {
                    DB::table('libro_diario_cierre')->where('id', $r->id)->update([
                        'correlativo' => $seq,
                        'codigo_rd' => $codigoRd,
                    ]);
                    $seq++;

                    break;
                }
                $seq++;
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('libro_diario_cierre')) {
            return;
        }

        Schema::table('libro_diario_cierre', function (Blueprint $table) {
            if (Schema::hasColumn('libro_diario_cierre', 'codigo_rd')) {
                $table->dropUnique(['codigo_rd']);
            }
        });

        Schema::table('libro_diario_cierre', function (Blueprint $table) {
            if (Schema::hasColumn('libro_diario_cierre', 'codigo_rd')) {
                $table->dropColumn('codigo_rd');
            }
            if (Schema::hasColumn('libro_diario_cierre', 'correlativo')) {
                $table->dropColumn('correlativo');
            }
        });
    }
};
