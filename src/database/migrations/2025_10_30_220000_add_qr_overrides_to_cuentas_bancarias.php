<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            // Marcadores de segmentación por tipo de doc
            if (!Schema::hasColumn('cuentas_bancarias', 'I_R')) {
                $table->tinyInteger('I_R')->nullable()->comment('0=Recibo, 1=Factura');
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'doc_tipo_preferido')) {
                $table->string('doc_tipo_preferido', 1)->nullable()->comment('R o F');
            }
            // Overrides de configuración QR por cuenta (todas opcionales)
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_url_auth')) {
                $table->string('qr_url_auth')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_api_key')) {
                $table->string('qr_api_key')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_username')) {
                $table->string('qr_username')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_password')) {
                $table->string('qr_password')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_url_transfer')) {
                $table->string('qr_url_transfer')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_api_key_servicio')) {
                $table->string('qr_api_key_servicio')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_http_verify_ssl')) {
                $table->boolean('qr_http_verify_ssl')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_http_timeout')) {
                $table->integer('qr_http_timeout')->nullable();
            }
            if (!Schema::hasColumn('cuentas_bancarias', 'qr_http_connect_timeout')) {
                $table->integer('qr_http_connect_timeout')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            // Nota: mantener columnas si están en uso; sólo eliminar si existen
            if (Schema::hasColumn('cuentas_bancarias', 'I_R')) $table->dropColumn('I_R');
            if (Schema::hasColumn('cuentas_bancarias', 'doc_tipo_preferido')) $table->dropColumn('doc_tipo_preferido');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_url_auth')) $table->dropColumn('qr_url_auth');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_api_key')) $table->dropColumn('qr_api_key');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_username')) $table->dropColumn('qr_username');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_password')) $table->dropColumn('qr_password');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_url_transfer')) $table->dropColumn('qr_url_transfer');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_api_key_servicio')) $table->dropColumn('qr_api_key_servicio');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_http_verify_ssl')) $table->dropColumn('qr_http_verify_ssl');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_http_timeout')) $table->dropColumn('qr_http_timeout');
            if (Schema::hasColumn('cuentas_bancarias', 'qr_http_connect_timeout')) $table->dropColumn('qr_http_connect_timeout');
        });
    }
};
