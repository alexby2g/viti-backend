<?php

namespace Database\Seeders;

use App\Models\PlanViti;
use Illuminate\Database\Seeder;

class VitiPlatformSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * VITI Core ya no provisiona empresas ni aplicaciones específicas de
         * clientes. Cada sistema se registra desde el flujo comercial/Studio
         * y mantiene su código y despliegue independientes.
         *
         * Los datos históricos existentes tampoco se borran desde un seeder.
         */
        $descriptions = [
            'basico-1800' => 'Para empezar con un sistema sencillo que organice un proceso principal de tu negocio, con pocos usuarios y funciones esenciales.',
            'profesional-1950' => 'Para negocios que necesitan controlar más procesos, usuarios, pagos, reportes o integraciones dentro de una solución más completa.',
            'empresa-2500' => 'Para empresas con más usuarios, mayor volumen de trabajo o varios procesos que necesitan una solución más amplia y escalable.',
            'personalizado' => 'Para necesidades especiales, varias sucursales, aplicaciones móviles, integraciones o proyectos que necesitan una cotización a medida.',
        ];

        foreach ($descriptions as $code => $description) {
            PlanViti::query()->where('codigo', $code)->update(['descripcion'=>$description]);
        }
    }
}
