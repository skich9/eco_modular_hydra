<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // qr_transacciones
        Schema::create('qr_transacciones', function (Blueprint $table) {
            $table->integer('id_qr_transaccion')->primary();
            $table->integer('id_usuario');
            $table->integer('nro_factura')->nullable();
            $table->integer('anio')->nullable();
            $table->integer('nro_recibo')->nullable();
            $table->integer('anio_recibo')->nullable();
            $table->string('id_cuenta_bancaria', 30);
            $table->string('alias', 50)->nullable();
            $table->integer('codigo_qr');
            $table->unsignedBigInteger('cod_ceta');
            $table->string('cod_pensum', 50);
            $table->string('tipo_inscripcion', 20);
            $table->integer('id_cuota')->nullable();
            $table->string('id_forma_cobro', 10);
            $table->decimal('monto_total', 10, 2);
            $table->string('moneda', 3)->nullable();
            $table->enum('estado', ['generado','escaneado','procesando','completado','expirado','cancelado'])->default('generado');
            $table->string('detalle_glosa', 30)->nullable();
            $table->timestamp('fecha_generacion')->useCurrent();
            $table->timestamp('fecha_expiracion');
            $table->string('nro_autorizacion', 100)->nullable();
            $table->timestamps();
        });

        // qr_conceptos_detalle
        Schema::create('qr_conceptos_detalle', function (Blueprint $table) {
            $table->integer('id_qr_conceptos_detalle', true); // AUTO_INCREMENT primary
            $table->integer('id_qr_transaccion');
            $table->string('tipo_concepto', 50);
            $table->integer('nro_cobro')->nullable();
            $table->string('concepto', 50);
            $table->text('observaciones')->nullable();
            $table->decimal('precio_unitario', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->integer('orden')->default(1);
            $table->timestamps();
        });

        // qr_estados_log
        Schema::create('qr_estados_log', function (Blueprint $table) {
            $table->integer('id_qr_estado_log', true); // AUTO_INCREMENT primary
            $table->integer('id_qr_transaccion');
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->string('motivo_cambio', 20);
            $table->string('usuario', 50)->nullable();
            $table->timestamp('fecha_cambio')->useCurrent();
            $table->timestamps();
        });

        // qr_respuestas_banco
        Schema::create('qr_respuestas_banco', function (Blueprint $table) {
            $table->integer('id_respuesta_banco', true); // AUTO_INCREMENT primary
            $table->integer('id_qr_transaccion');
            $table->string('banco', 50);
            $table->string('codigo_respuesta', 20)->nullable();
            $table->text('mensaje_respuesta')->nullable();
            $table->string('numero_autorizacion', 100)->nullable();
            $table->string('numero_referencia', 100)->nullable();
            $table->string('numero_comprobante', 100)->nullable();
            $table->timestamp('fecha_respuesta')->useCurrent();
        });

        // qr_configuracion
        Schema::create('qr_configuracion', function (Blueprint $table) {
            $table->integer('id_qr_config', true); // AUTO_INCREMENT primary
            $table->string('cod_pensum', 50)->nullable();
            $table->integer('tiempo_expiracion_minutos')->default(1440);
            $table->decimal('monto_minimo', 10, 2)->default(200);
            $table->boolean('permite_pago_parcial')->default(false);
            $table->text('template_mensaje')->nullable();
            $table->boolean('estado')->default(true);
        });

        // Foreign Keys
        Schema::table('qr_transacciones', function (Blueprint $table) {
            $table->foreign('cod_ceta')->references('cod_ceta')->on('estudiantes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('id_forma_cobro')->references('id_forma_cobro')->on('formas_cobro')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
            $table->foreign('id_qr_transaccion')->references('id_qr_transaccion')->on('qr_transacciones')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::table('qr_estados_log', function (Blueprint $table) {
            $table->foreign('id_qr_transaccion')->references('id_qr_transaccion')->on('qr_transacciones')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::table('qr_respuestas_banco', function (Blueprint $table) {
            $table->foreign('id_qr_transaccion')->references('id_qr_transaccion')->on('qr_transacciones')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('qr_respuestas_banco', function (Blueprint $table) {
            try { $table->dropForeign(['id_qr_transaccion']); } catch (\Throwable $e) {}
        });
        Schema::table('qr_estados_log', function (Blueprint $table) {
            try { $table->dropForeign(['id_qr_transaccion']); } catch (\Throwable $e) {}
        });
        Schema::table('qr_conceptos_detalle', function (Blueprint $table) {
            try { $table->dropForeign(['id_qr_transaccion']); } catch (\Throwable $e) {}
        });
        Schema::table('qr_transacciones', function (Blueprint $table) {
            try { $table->dropForeign(['cod_ceta']); } catch (\Throwable $e) {}
            try { $table->dropForeign(['id_forma_cobro']); } catch (\Throwable $e) {}
        });

        Schema::dropIfExists('qr_respuestas_banco');
        Schema::dropIfExists('qr_estados_log');
        Schema::dropIfExists('qr_conceptos_detalle');
        Schema::dropIfExists('qr_configuracion');
        Schema::dropIfExists('qr_transacciones');
    }
};
