<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Tabla cabecera ─────────────────────────────────────────────────────
        if (!Schema::hasTable('recepcion_ingresos')) {
            Schema::create('recepcion_ingresos', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Identifica de qué carrera proviene el registro (EEA | MEA)
                $table->string('codigo_carrera', 10)->comment('EEA = Electrónica, MEA = Mecánica');

                // Fechas
                $table->date('fecha_recepcion');
                $table->dateTime('fecha_registro')->useCurrent();

                // Participantes (nombres de usuario del sistemaEco)
                $table->string('usuario_entregue1', 100)->comment('Cajero/a que entrega – por defecto el usuario logueado');
                $table->string('usuario_recibi1', 100)->comment('Tesorero/a que recibe (obligatorio)');
                $table->string('usuario_entregue2', 100)->nullable()->comment('Segundo cajero/a (opcional)');
                $table->string('usuario_recibi2', 100)->nullable()->comment('Segundo tesorero/a (opcional)');

                // Usuario que registró en el sistema
                $table->string('usuario_registro', 100)->comment('id_usuario o nickname de quien creó el registro');

                // Código correlativo, ej: EEA-03-028 | MEA-03-015
                $table->string('cod_documento', 60)->comment('Código correlativo del documento de recepción');
                $table->unsignedInteger('num_documento')->default(0)->comment('Número secuencial dentro de la carrera');

                // Datos económicos
                $table->decimal('monto_total', 12, 2)->nullable();
                $table->unsignedInteger('id_actividad_economica')->nullable();
                $table->boolean('es_ingreso_libro_diario')->default(true);

                // Estado
                $table->boolean('anulado')->default(false);
                $table->text('motivo_anulacion')->nullable();

                // Texto libre
                $table->text('observacion')->nullable();

                $table->timestamps();

                // Índices
                $table->index('codigo_carrera', 'idx_recep_carrera');
                $table->index('fecha_recepcion', 'idx_recep_fecha');
                $table->index('id_actividad_economica', 'idx_recep_actividad');
                $table->unique(['codigo_carrera', 'cod_documento'], 'uq_recep_carrera_documento');
            });
        }

        // ─── Tabla detalle ───────────────────────────────────────────────────────
        if (!Schema::hasTable('recepcion_ingreso_detalles')) {
            Schema::create('recepcion_ingreso_detalles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('recepcion_ingreso_id');

                // Libro diario asociado
                $table->string('usuario_libro', 100)->nullable()->comment('Tesorero del libro diario');
                $table->string('cod_libro_diario', 100)->nullable()->comment('Ej: RD-EEA-03-059');
                $table->date('fecha_inicial_libros')->nullable();
                $table->date('fecha_final_libros');

                // Totales por tipo de pago
                $table->decimal('total_deposito', 12, 2)->default(0)->nullable();
                $table->decimal('total_traspaso', 12, 2)->default(0)->nullable();
                $table->decimal('total_recibos', 12, 2)->default(0)->nullable();
                $table->decimal('total_facturas', 12, 2)->default(0)->nullable();
                $table->decimal('total_entregado', 12, 2)->default(0)->nullable();
                $table->decimal('faltante_sobrante', 12, 2)->nullable();

                // FK con cascade para que al eliminar la cabecera se borren los detalles
                $table->foreign('recepcion_ingreso_id', 'fk_recep_det_cabecera')
                    ->references('id')
                    ->on('recepcion_ingresos')
                    ->onUpdate('cascade')
                    ->onDelete('cascade');

                $table->index('recepcion_ingreso_id', 'idx_recep_det_cabecera');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcion_ingreso_detalles');
        Schema::dropIfExists('recepcion_ingresos');
    }
};
