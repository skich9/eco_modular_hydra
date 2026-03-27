<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $this->call([
            // Usuarios y permisos
            RolesFuncionesActualesSeeder::class,
            UsuarioSeeder::class,
            // Asegurar que el admin tenga funciones asignadas para no bloquear el sistema
            AsignarFuncionesUsuarioAdminSeeder::class,

            // Parámetros del sistema (CRÍTICOS para cobrar)
            ParametrosGeneralesSeeder::class,
            ParametrosEconomicosSeeder::class,
            ParametroCuotaSeeder::class,
            ParametrosCostosSeeder::class,

            // Tablas SIN (facturación electrónica)
            SinActividadesSeeder::class,
            // SinDatosSincronizacionSeeder::class,  // Requiere archivo SQL externo
            // SinCafcSeeder::class,  // Causa duplicados
            TipoCobroSeeder::class,
            // sin_forma_cobro depende de formas_cobro -> se ejecuta más abajo

            // Descuentos y becas
            // DefDescuentosBecaSeeder::class,  // Causa duplicados

            // Estructura académica
            CarreraSeeder::class,
            // PensumSeeder::class,  // Causa duplicados
            PensumResolucionSeeder::class,
            GestionSeeder::class,

            // Costos y cobros
            // CostoSemestralSeeder::class,  // Problemas con factory
            AsignacionCostosSeeder::class,
            FormasCobroSeeder::class,
            // Luego de formas_cobro, poblar el mapeo SIN
            SinFormaCobroSeeder::class,
            // SinListLeyendaFacturaSeeder::class,  // Problemas de estructura
            SinMotivoAnulacionFacturaSeeder::class,
            CuentasBancariasSeeder::class,
            // CuotasSeeder::class,  // Problemas de estructura

            // Catálogos y parametrizaciones adicionales
            ItemsCobroSeeder::class,
            // Materia depende de pensums
            MateriaSeeder::class,
            // Otros ingresos (catálogo y directivas de demostración)
            OtrosIngresosCatalogosSeeder::class,
        ]);
    }
}
