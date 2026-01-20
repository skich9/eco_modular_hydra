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
            RolSeeder::class,
            FuncionSeeder::class,
            UsuarioSeeder::class,

            // Parámetros del sistema (CRÍTICOS para cobrar)
            ParametrosGeneralesSeeder::class,
            ParametrosEconomicosSeeder::class,
            ParametroCuotaSeeder::class,
            ParametrosCostosSeeder::class,

            // Tablas SIN (facturación electrónica)
            SinActividadesSeeder::class,
            SinDatosSincronizacionSeeder::class,
            SinCafcSeeder::class,
            TipoCobroSeeder::class,
            // sin_forma_cobro depende de formas_cobro -> se ejecuta más abajo

            // Descuentos y becas
            DefDescuentosBecaSeeder::class,

            // Estructura académica
            CarreraSeeder::class,
            PensumSeeder::class,
            PensumResolucionSeeder::class,
            GestionSeeder::class,

            // Costos y cobros
            CostoSemestralSeeder::class,
            AsignacionCostosSeeder::class,
            FormasCobroSeeder::class,
            // Luego de formas_cobro, poblar el mapeo SIN
            SinFormaCobroSeeder::class,
            SinListLeyendaFacturaSeeder::class,
            SinMotivoAnulacionFacturaSeeder::class,
            CuentasBancariasSeeder::class,
            CuotasSeeder::class,

            // Catálogos y parametrizaciones adicionales
            ItemsCobroSeeder::class,
            // Materia depende de pensums
            MateriaSeeder::class,
        ]);
    }
}
